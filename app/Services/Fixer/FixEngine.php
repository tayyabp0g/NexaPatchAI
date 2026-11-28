<?php

namespace App\Services\Fixer;

use App\Exceptions\LlmUnavailable;
use App\Jobs\RunScan;
use App\Models\FixRun;
use App\Models\Patch;
use App\Models\Review;
use App\Models\Scan;
use App\Models\User;
use App\Models\VerificationResult;
use App\Services\AuditLogger;
use App\Services\Scanner\Redactor;
use App\Services\Scanner\StaticScanner;
use App\Services\UploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class FixEngine
{
    public function __construct(protected UploadService $uploads)
    {
    }

    /**
     * Propose patches for open findings in the fix run's scan.
     */
    public function propose(FixRun $run): void
    {
        $run->update(['status' => 'running', 'error_message' => null]);
        $scan = $run->scan()->with('findings', 'upload')->firstOrFail();
        $upload = $scan->upload;

        $findings = $scan->findings()
            ->where('status', 'open')
            ->get();

        $skipped = [];
        $withLines = [];

        foreach ($findings as $f) {
            if ($f->line_start === null) {
                $skipped[] = [
                    'finding_id' => $f->id,
                    'rule_id' => $f->rule_id,
                    'title' => $f->title,
                    'file' => $f->file_path,
                    'line' => null,
                    'reason' => 'Finding does not specify a line number (dependency or project-level).',
                    'manual_step' => $f->remediation ?? 'Review and update the affected package or configuration.',
                ];
            } else {
                $withLines[] = $f;
            }
        }

        $byFile = collect($withLines)->groupBy('file_path');

        $llmClient = $run->use_ai ? LlmFactory::make() : null;
        $aiFixer = $llmClient ? new AiFixer($llmClient) : null;
        $aiAvailable = (bool) $aiFixer;
        $aiCalls = 0;
        $aiStoppedReason = null;
        $maxCalls = (int) config('nexapatch.llm.max_calls', 30);
        $delayMs = (int) config('nexapatch.llm.delay_ms', 4500);

        foreach ($byFile as $filePath => $fileFindings) {
            $absPath = $this->uploads->abs($upload->storage_path.'/source/'.$filePath);
            if (! is_file($absPath)) {
                foreach ($fileFindings as $ff) {
                    $skipped[] = [
                        'finding_id' => $ff->id,
                        'rule_id' => $ff->rule_id,
                        'title' => $ff->title,
                        'file' => $filePath,
                        'line' => $ff->line_start,
                        'reason' => 'Source file not found on disk.',
                        'manual_step' => $ff->remediation,
                    ];
                }
                continue;
            }

            $rawContent = (string) file_get_contents($absPath);
            $fileLines = preg_split('/\R/', $rawContent) ?: [];
            $kind = StaticScanner::kindOf(basename($filePath));

            // Process findings from LAST line to FIRST to keep line numbers valid
            $sortedFindings = $fileFindings->sortByDesc('line_start');

            foreach ($sortedFindings as $finding) {
                $lineNo = (int) $finding->line_start;
                $currLine = $fileLines[$lineNo - 1] ?? '';
                $prevLine = $lineNo > 1 ? ($fileLines[$lineNo - 2] ?? null) : null;

                $proposed = null;
                $method = 'rules';

                // 1. Try deterministic line fixer
                $ruleFix = LineFixers::fix($finding->rule_id, $kind, $currLine, $prevLine);
                if ($ruleFix !== null) {
                    $proposed = [
                        'start' => $lineNo,
                        'remove' => 1,
                        'add' => $ruleFix['lines'],
                        'note' => $ruleFix['note'],
                        'secrets' => $ruleFix['secrets'] ?? [],
                    ];
                }

                // 2. Try AI fixer if rules cannot fix and AI is requested
                if ($proposed === null && $aiAvailable && $aiFixer !== null) {
                    if ($aiCalls >= $maxCalls) {
                        $aiAvailable = false;
                        $aiStoppedReason = "Reached maximum allowed AI calls limit ({$maxCalls}).";
                    } else {
                        if ($aiCalls > 0 && $delayMs > 0) {
                            usleep($delayMs * 1000);
                        }

                        try {
                            $aiRes = $aiFixer->propose($finding, $kind, $fileLines, $lineNo);
                            $aiCalls++;

                            if ($aiRes['ok']) {
                                $method = 'ai';
                                $proposed = [
                                    'start' => $aiRes['start'],
                                    'remove' => $aiRes['remove'],
                                    'add' => $aiRes['add'],
                                    'note' => $aiRes['note'],
                                    'secrets' => [],
                                ];
                            } else {
                                $skipped[] = [
                                    'finding_id' => $finding->id,
                                    'rule_id' => $finding->rule_id,
                                    'title' => $finding->title,
                                    'file' => $filePath,
                                    'line' => $lineNo,
                                    'reason' => $aiRes['reason'] ?? 'AI could not safely repair this issue.',
                                    'manual_step' => $finding->remediation,
                                ];
                                continue;
                            }
                        } catch (LlmUnavailable $e) {
                            $aiAvailable = false;
                            $aiStoppedReason = 'AI unavailable (rate limit or connection error): '.$e->getMessage();
                            $skipped[] = [
                                'finding_id' => $finding->id,
                                'rule_id' => $finding->rule_id,
                                'title' => $finding->title,
                                'file' => $filePath,
                                'line' => $lineNo,
                                'reason' => 'AI unavailable for this issue (rate limited).',
                                'manual_step' => $finding->remediation,
                            ];
                            continue;
                        } catch (\Throwable $e) {
                            $skipped[] = [
                                'finding_id' => $finding->id,
                                'rule_id' => $finding->rule_id,
                                'title' => $finding->title,
                                'file' => $filePath,
                                'line' => $lineNo,
                                'reason' => 'AI error: '.$e->getMessage(),
                                'manual_step' => $finding->remediation,
                            ];
                            continue;
                        }
                    }
                }

                // 3. If neither rules nor AI could propose an edit
                if ($proposed === null) {
                    $skipped[] = [
                        'finding_id' => $finding->id,
                        'rule_id' => $finding->rule_id,
                        'title' => $finding->title,
                        'file' => $filePath,
                        'line' => $lineNo,
                        'reason' => 'No automated rule or AI fix available for this finding.',
                        'manual_step' => $finding->remediation,
                    ];
                    continue;
                }

                // 4. Verify candidate edit with FixPolicy
                $check = FixPolicy::check(
                    $filePath,
                    $kind,
                    $finding->rule_id,
                    $fileLines,
                    $lineNo,
                    $proposed['start'],
                    $proposed['remove'],
                    $proposed['add']
                );

                $removedLines = array_slice($fileLines, $proposed['start'] - 1, $proposed['remove']);
                $beforeSha = sha1(implode("\n", $removedLines));
                $beforeDisplay = array_map(fn ($l) => Redactor::redact($l, true), $removedLines);

                $diff = $this->buildDiff(
                    $filePath,
                    $proposed['start'],
                    $beforeDisplay,
                    $proposed['add']
                );

                $patch = Patch::create([
                    'finding_id' => $finding->id,
                    'fix_run_id' => $run->id,
                    'diff_text' => $diff,
                    'change_summary' => $proposed['note'],
                    'model_name' => $method === 'ai' && $aiFixer ? $aiFixer->model() : null,
                    'attempt_no' => 1,
                    'status' => $check['verified'] ? 'verified' : 'unverified',
                    'method' => $method,
                    'edit' => [
                        'file' => $filePath,
                        'start' => $proposed['start'],
                        'remove_count' => $proposed['remove'],
                        'before_sha' => $beforeSha,
                        'before_display' => $beforeDisplay,
                        'add' => $proposed['add'],
                        'rule' => $finding->rule_id,
                        'note' => $proposed['note'],
                    ],
                ]);

                foreach ($check['results'] as $res) {
                    VerificationResult::create([
                        'patch_id' => $patch->id,
                        'check_type' => $res['check_type'],
                        'result' => $res['result'],
                        'log_excerpt' => $res['log_excerpt'],
                    ]);
                }

                // If verified, apply to our working copy of the file lines
                if ($check['verified']) {
                    array_splice($fileLines, $proposed['start'] - 1, $proposed['remove'], $proposed['add']);
                }
            }
        }

        $run->update([
            'status' => 'ready',
            'summary' => [
                'skipped' => $skipped,
                'ai_calls' => $aiCalls,
                'ai_stopped' => $aiStoppedReason,
            ],
        ]);
    }

    /**
     * Build the repaired ZIP from approved patches and verify result via rescan.
     */
    public function build(FixRun $run, array $approvedPatchIds, User $user): FixRun
    {
        if ($run->status !== 'ready') {
            throw ValidationException::withMessages([
                'fix' => 'This repair run has already been processed. Start a new repair run to try again.',
            ]);
        }

        $run->loadMissing('scan.upload.project', 'patches.finding');
        $scan = $run->scan;
        $upload = $scan->upload;
        $project = $upload->project;

        $patches = $run->patches;
        $approvedPatchIds = array_values(array_unique(array_map('intval', $approvedPatchIds)));
        $verifiedIds = $run->patches()
            ->where('status', 'verified')
            ->whereIn('id', $approvedPatchIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($approvedPatchIds === [] || count($verifiedIds) !== count($approvedPatchIds)) {
            throw ValidationException::withMessages([
                'patches' => 'Select at least one verified patch from this repair run.',
            ]);
        }

        $applied = [];
        $rejected = [];
        $conflicts = [];
        $appliedPatches = [];

        // 1. Separate approved vs rejected
        foreach ($patches as $patch) {
            $isApproved = in_array($patch->id, $approvedPatchIds, false) && $patch->status === 'verified';

            Review::create([
                'patch_id' => $patch->id,
                'reviewer_id' => $user->id,
                'decision' => $isApproved ? 'approved' : 'rejected',
                'comment' => $isApproved ? 'Approved by user.' : 'Rejected or unverified.',
                'decided_at' => now(),
            ]);

            if ($isApproved) {
                $appliedPatches[] = $patch;
            } else {
                $patch->update(['status' => 'rejected']);
                $rejected[] = $patch->id;
            }
        }

        // 2. Group approved patches by file and apply in descending start order
        $byFile = collect($appliedPatches)->groupBy(fn ($p) => $p->edit['file']);
        $modifiedFiles = [];
        $secretsToMove = [];
        $changeLog = [];

        foreach ($byFile as $file => $filePatches) {
            $absPath = $this->uploads->abs($upload->storage_path.'/source/'.$file);
            $rawContent = is_file($absPath) ? (string) file_get_contents($absPath) : '';
            $lines = preg_split('/\R/', $rawContent) ?: [];

            $sorted = $filePatches->sortByDesc(fn ($p) => $p->edit['start']);

            foreach ($sorted as $patch) {
                $start = (int) $patch->edit['start'];
                $removeCount = (int) $patch->edit['remove_count'];
                $expectedSha = $patch->edit['before_sha'];

                $currentLines = array_slice($lines, $start - 1, $removeCount);
                $currentSha = sha1(implode("\n", $currentLines));

                if ($currentSha !== $expectedSha) {
                    $conflicts[] = [
                        'patch_id' => $patch->id,
                        'file' => $file,
                        'line' => $start,
                        'reason' => 'Target line content changed or collided with another edit.',
                    ];
                    $patch->update(['status' => 'unverified']);
                    continue;
                }

                // Recompute secrets to move directly from original line at build time
                if (in_array($patch->finding->rule_id, LineFixers::SECRET_RULES, true)) {
                    $origLine = $currentLines[0] ?? '';
                    $kind = StaticScanner::kindOf(basename($file));
                    $freshFix = LineFixers::fix($patch->finding->rule_id, $kind, $origLine);
                    if (! empty($freshFix['secrets'])) {
                        $secretsToMove = array_merge($secretsToMove, $freshFix['secrets']);
                    }
                }

                array_splice($lines, $start - 1, $removeCount, $patch->edit['add']);
                $patch->update(['status' => 'applied']);
                $applied[] = $patch->id;

                // Mark original finding status as fixed
                $patch->finding->update(['status' => 'fixed']);

                $changeLog[] = [
                    'file' => $file,
                    'line' => $start,
                    'rule' => $patch->finding->rule_id,
                    'title' => $patch->finding->title,
                    'method' => $patch->method,
                    'note' => $patch->change_summary,
                ];
            }

            $modifiedFiles[$file] = implode("\n", $lines);
        }

        if ($applied === []) {
            $message = 'No approved patches could be applied because all selected patches conflicted with the source files.';
            $run->update([
                'status' => 'failed',
                'failed_count' => count($conflicts),
                'error_message' => $message,
                'summary' => array_merge($run->summary ?? [], ['conflicts' => $conflicts]),
                'finished_at' => now(),
            ]);

            return $run->fresh(['patches', 'scan.upload.project']);
        }

        // 3. Assemble the ZIP archive
        $tmpZip = tempnam(sys_get_temp_dir(), 'np_fixed_').'.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create destination ZIP archive.');
        }

        if ($upload->is_archive) {
            $origZipPath = $this->uploads->originalPath($upload);
            if ($origZipPath && is_file($origZipPath)) {
                $origZip = new ZipArchive();
                if ($origZip->open($origZipPath) === true) {
                    for ($i = 0; $i < $origZip->numFiles; $i++) {
                        $entryName = $origZip->getNameIndex($i);
                        if ($entryName === false) {
                            continue;
                        }
                        $rel = $this->uploads->relativeFor($entryName);
                        if ($rel === null) {
                            continue;
                        }

                        if (isset($modifiedFiles[$rel])) {
                            $zip->addFromString($entryName, $modifiedFiles[$rel]);
                            unset($modifiedFiles[$rel]);
                        } else {
                            $stream = $origZip->getFromIndex($i);
                            if ($stream !== false) {
                                $zip->addFromString($entryName, $stream);
                            }
                        }
                    }
                    $origZip->close();
                }
            }
        } else {
            $rel = $upload->original_file ? basename($upload->original_file) : 'index.php';
            if (isset($modifiedFiles[$upload->original_file])) {
                $zip->addFromString($rel, $modifiedFiles[$upload->original_file]);
                unset($modifiedFiles[$upload->original_file]);
            } elseif (isset($modifiedFiles[$rel])) {
                $zip->addFromString($rel, $modifiedFiles[$rel]);
                unset($modifiedFiles[$rel]);
            } else {
                $src = $this->uploads->originalPath($upload);
                if ($src && is_file($src)) {
                    $zip->addFromString($rel, (string) file_get_contents($src));
                }
            }
        }

        // Any remaining modified files not already matched in the archive
        foreach ($modifiedFiles as $rel => $content) {
            $zip->addFromString($rel, $content);
        }

        // Add NEXAPATCH-CHANGES.md
        $skippedItems = $run->summary['skipped'] ?? [];
        $changesDoc = $this->buildChangesDocument($changeLog, $skippedItems);
        $zip->addFromString('NEXAPATCH-CHANGES.md', $changesDoc);

        // Add NEXAPATCH_SECRETS_TO_MOVE.nexapatch if secrets were found
        if ($secretsToMove !== []) {
            $secretsDoc = "# NEXAPATCH AI - Secrets extracted from source code\n"
                ."# WARNING: These secrets were hard-coded in plaintext. Rotate all of them immediately!\n"
                ."# Move these key-value pairs into your secure .env or hosting environment variables.\n\n";
            foreach ($secretsToMove as $k => $v) {
                $secretsDoc .= "{$k}={$v}\n";
            }
            $zip->addFromString('NEXAPATCH_SECRETS_TO_MOVE.nexapatch', $secretsDoc);
        }

        $zip->close();

        // 4. Store fixed ZIP permanently
        $zipSha256 = hash_file('sha256', $tmpZip);
        $permanentRel = "projects/{$project->id}/fixes/{$run->id}/fixed.zip";
        Storage::disk(UploadService::DISK)->put($permanentRel, (string) file_get_contents($tmpZip));

        // 5. Register patched upload and run rescan
        $uploadedFile = new UploadedFile($tmpZip, 'fixed_'.$upload->original_name, 'application/zip', null, true);
        $patchedUpload = $this->uploads->store($project, $uploadedFile, $user, 'patched');

        $afterScan = Scan::create([
            'upload_id' => $patchedUpload->id,
            'started_by' => $user->id,
            'status' => 'queued',
        ]);

        RunScan::dispatchSync($afterScan->id);
        $afterScan->refresh();

        $beforeScore = (int) $scan->score;
        $beforeGrade = $scan->grade ?? 'F';
        $afterScore = (int) $afterScan->score;
        $afterGrade = $afterScan->grade ?? 'F';
        $beforeOpen = (int) ($scan->summary['total_open'] ?? $scan->findings()->where('status', 'open')->count());
        $afterOpen = (int) ($afterScan->summary['total_open'] ?? $afterScan->findings()->where('status', 'open')->count());

        $summary = array_merge($run->summary ?? [], [
            'before_score' => $beforeScore,
            'before_grade' => $beforeGrade,
            'after_score' => $afterScore,
            'after_grade' => $afterGrade,
            'score_diff' => $afterScore - $beforeScore,
            'before_open' => $beforeOpen,
            'after_open' => $afterOpen,
            'findings_resolved' => max(0, $beforeOpen - $afterOpen),
            'conflicts' => $conflicts,
        ]);

        $run->update([
            'status' => 'completed',
            'applied_count' => count($applied),
            'skipped_count' => count($run->summary['skipped'] ?? []),
            'failed_count' => count($conflicts),
            'zip_path' => $permanentRel,
            'zip_sha256' => $zipSha256,
            'patched_upload_id' => $patchedUpload->id,
            'after_scan_id' => $afterScan->id,
            'summary' => $summary,
            'finished_at' => now(),
        ]);

        AuditLogger::log('fix.built', $run, [
            'fix_run_id' => $run->id,
            'applied' => count($applied),
            'conflicts' => count($conflicts),
            'before_score' => $beforeScore,
            'after_score' => $afterScore,
        ], $user->id);

        @unlink($tmpZip);

        return $run->fresh(['patchedUpload', 'afterScan', 'patches']);
    }

    /**
     * Builds unified diff text for a patch.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    private function buildDiff(string $file, int $start, array $before, array $after): string
    {
        $removeCount = count($before);
        $addCount = count($after);

        $out = [];
        $out[] = "--- a/{$file}";
        $out[] = "+++ b/{$file}";
        $out[] = "@@ -{$start},{$removeCount} +{$start},{$addCount} @@";

        foreach ($before as $line) {
            $out[] = '- '.$line;
        }
        foreach ($after as $line) {
            $out[] = '+ '.$line;
        }

        return implode("\n", $out);
    }

    /**
     * Build the markdown documentation included in the repaired ZIP.
     */
    private function buildChangesDocument(array $applied, array $skipped): string
    {
        $doc = "# NexaPatch AI Repair Summary\n\n"
            ."Generated on: ".now()->toIso8601String()."\n\n"
            ."This archive contains security fixes applied to your source code without altering your application logic.\n\n"
            ."## Applied Changes (".count($applied).")\n\n";

        if ($applied === []) {
            $doc .= "_No automatic fixes were applied._\n\n";
        } else {
            $doc .= "| File | Line | Rule | Method | Details |\n"
                ."|---|---|---|---|---|\n";
            foreach ($applied as $c) {
                $doc .= "| `{$c['file']}` | {$c['line']} | `{$c['rule']}` | {$c['method']} | ".htmlspecialchars($c['note'])." |\n";
            }
            $doc .= "\n";
        }

        $doc .= "## Manual Remediation Items (".count($skipped).")\n\n";
        if ($skipped === []) {
            $doc .= "_All detected issues were repaired automatically._\n\n";
        } else {
            $doc .= "The following issues could not be safely repaired automatically and require developer attention:\n\n";
            foreach ($skipped as $s) {
                $loc = $s['file'].($s['line'] ? ":{$s['line']}" : '');
                $doc .= "### `{$s['rule_id']}` in `{$loc}`\n"
                    ."- **Issue:** {$s['title']}\n"
                    ."- **Why skipped:** {$s['reason']}\n"
                    ."- **Remediation action:** {$s['manual_step']}\n\n";
            }
        }

        return $doc;
    }
}
