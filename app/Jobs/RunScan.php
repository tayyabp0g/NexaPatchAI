<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Services\AuditLogger;
use App\Services\ReportService;
use App\Services\Scanner\ExternalScanner;
use App\Services\Scanner\OsvScanner;
use App\Services\Scanner\ScanSummary;
use App\Services\Scanner\StaticScanner;
use App\Services\UploadService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Runs all scanners on one upload and stores the findings.
 * Reads only the stored source files; never executes uploaded code (policy 9).
 */
class RunScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $scanId) {}

    public function handle(UploadService $uploads, ReportService $reports, ExternalScanner $externalScanner): void
    {
        $scan = Scan::with('upload')->find($this->scanId);
        if (! $scan) {
            return;
        }

        @set_time_limit(0);
        $started = microtime(true);
        $scan->update(['status' => 'running', 'started_at' => now(), 'error_message' => null]);

        try {
            $dir = $uploads->abs($scan->upload->storage_path.'/source');
            if ($scan->upload->storage_path === '' || ! is_dir($dir)) {
                throw new RuntimeException('The stored source files for this upload were not found.');
            }

            $result = (new StaticScanner)->scan($dir);
            $findings = $result['findings'];
            $engines = ['static'];

            $external = $externalScanner->scan($dir);
            $findings = array_merge($findings, $external['findings']);
            foreach ($external['engines'] as $engine => $status) {
                $engines[] = $status === 'completed' ? $engine : $engine.':'.$status;
            }

            $lock = $dir.'/composer.lock';
            if (config('nexapatch.osv_enabled') && is_file($lock)) {
                $osv = (new OsvScanner)->scanComposerLock($lock);
                $findings = array_merge($findings, $osv['findings']);
                $engines[] = $osv['ok'] ? 'osv' : 'osv:unavailable';
            }

            $findings = array_slice($findings, 0, (int) config('nexapatch.scan.max_findings', 3000));

            DB::transaction(function () use ($scan, $findings) {
                $scan->findings()->delete();
                $now = now();
                $rows = array_map(fn (array $f) => array_merge($f, [
                    'scan_id' => $scan->id,
                    'status' => 'open',
                    'references' => json_encode($f['references'] ?? []),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]), $findings);
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('findings')->insert($chunk);
                }
            });

            $scan->update([
                'status' => 'completed',
                'engines_used' => $engines,
                'files_scanned' => $result['files_scanned'],
                'files_skipped' => $result['files_skipped'],
                'finished_at' => now(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            ScanSummary::refresh($scan);
            $reports->invalidate($scan);

            AuditLogger::log('scan.completed', $scan, [
                'findings' => count($findings),
                'score' => $scan->score,
                'files' => $result['files_scanned'],
            ], $scan->started_by);
        } catch (Throwable $e) {
            report($e);
            $scan->update([
                'status' => 'failed',
                'error_message' => Str::limit($e->getMessage(), 300),
                'finished_at' => now(),
            ]);
            AuditLogger::log('scan.failed', $scan, ['error' => Str::limit($e->getMessage(), 200)], $scan->started_by);
        }
    }
}
