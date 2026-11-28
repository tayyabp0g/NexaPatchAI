<?php

namespace App\Services\Fixer;

use App\Services\Scanner\Redactor;
use App\Services\Scanner\StaticScanner;

/**
 * Verifies proposed code edits against safety policy, language syntax and security rescan.
 *
 * Implements SRS verification policies:
 * - Changes must be minimal and strictly local (+- 1 line of the finding).
 * - Must not introduce masked placeholders (****) or hard-coded secrets.
 * - Code must parse cleanly.
 * - Target vulnerability must be resolved without introducing new rule violations.
 */
class FixPolicy
{
    /**
     * @param string $filePath Relative file path
     * @param string $kind File extension/kind (php, js, json, etc.)
     * @param string $ruleId Target security rule ID
     * @param list<string> $beforeLines Original file lines (0-indexed)
     * @param int $findingLine 1-indexed line of the finding
     * @param int $startLine 1-indexed starting line of the proposed edit
     * @param int $removeCount Number of lines replaced
     * @param list<string> $addLines Replacement lines
     * @return array{verified: bool, after_lines: list<string>, results: list<array{check_type: string, result: string, log_excerpt: string}>}
     */
    public static function check(
        string $filePath,
        string $kind,
        string $ruleId,
        array $beforeLines,
        int $findingLine,
        int $startLine,
        int $removeCount,
        array $addLines
    ): array {
        // ------------------------------------------------------------- 1. Policy check
        $policyPassed = true;
        $policyLogs = [];

        $endLine = $startLine + max(0, $removeCount - 1);
        $minAllowed = max(1, $findingLine - 1);
        $maxAllowed = $findingLine + 1;

        if ($startLine < $minAllowed || $endLine > $maxAllowed) {
            $policyPassed = false;
            $policyLogs[] = "Edit touches lines outside allowed range ({$startLine}..{$endLine} not within {$minAllowed}..{$maxAllowed}).";
        }

        if (count($addLines) > 12) {
            $policyPassed = false;
            $policyLogs[] = 'Edit adds more than 12 lines ('.count($addLines).' lines).';
        }

        $addedText = implode("\n", $addLines);
        if (str_contains($addedText, '****')) {
            $policyPassed = false;
            $policyLogs[] = 'Added lines contain masked secret placeholders (****).';
        }

        foreach ($addLines as $line) {
            $redacted = Redactor::redact($line, false);
            if ($redacted !== $line) {
                $policyPassed = false;
                $policyLogs[] = 'Added line appears to contain hard-coded secret values.';
                break;
            }
        }

        $policyResult = $policyPassed ? 'pass' : 'fail';
        $policyLog = $policyPassed
            ? 'Policy gate passed: changes are local, within line limits, and free of secrets.'
            : implode(' ', $policyLogs);

        // ------------------------------------------------------------- 2. Syntax check
        $afterLines = $beforeLines;
        array_splice($afterLines, max(0, $startLine - 1), $removeCount, $addLines);
        $beforeCode = implode("\n", $beforeLines);
        $afterCode = implode("\n", $afterLines);

        $syntaxResult = 'unavailable';
        $syntaxLog = "Syntax check unavailable for {$kind} files.";

        if (in_array($kind, ['php', 'phtml', 'inc'], true)) {
            $afterParsed = true;
            $syntaxError = null;

            try {
                token_get_all($afterCode, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $afterParsed = false;
                $syntaxError = $e->getMessage();
            } catch (\Throwable $e) {
                $afterParsed = false;
                $syntaxError = $e->getMessage();
            }

            if ($afterParsed) {
                $syntaxResult = 'pass';
                $syntaxLog = 'PHP syntax is valid.';
            } else {
                $syntaxResult = 'fail';
                $syntaxLog = 'PHP syntax error: '.($syntaxError ?? 'Parse error');
            }
        } elseif ($kind === 'json') {
            json_decode($afterCode);
            if (json_last_error() === JSON_ERROR_NONE) {
                $syntaxResult = 'pass';
                $syntaxLog = 'JSON syntax is valid.';
            } else {
                $syntaxResult = 'fail';
                $syntaxLog = 'JSON syntax error: '.json_last_error_msg();
            }
        }

        // ------------------------------------------------------------- 3. Rescan check
        $scanner = new StaticScanner();
        $beforeFindings = $scanner->scanContent($filePath, $kind, $beforeCode);
        $afterFindings = $scanner->scanContent($filePath, $kind, $afterCode);

        $beforeCounts = array_count_values(array_column($beforeFindings, 'rule_id'));
        $afterCounts = array_count_values(array_column($afterFindings, 'rule_id'));

        $targetBefore = $beforeCounts[$ruleId] ?? 0;
        $targetAfter = $afterCounts[$ruleId] ?? 0;
        $targetDropped = ($targetAfter < $targetBefore);

        $noRegressions = true;
        $regressedRule = null;
        foreach ($afterCounts as $rid => $count) {
            if ($rid === $ruleId) {
                continue;
            }
            $orig = $beforeCounts[$rid] ?? 0;
            if ($count > $orig) {
                $noRegressions = false;
                $regressedRule = $rid;
                break;
            }
        }

        if ($targetDropped && $noRegressions) {
            $rescanResult = 'pass';
            $rescanLog = "Target rule {$ruleId} count reduced ({$targetBefore} -> {$targetAfter}). No new issues introduced.";
        } else {
            $rescanResult = 'fail';
            $rescanLog = ! $targetDropped
                ? "Target rule {$ruleId} was not resolved ({$targetBefore} -> {$targetAfter})."
                : "Regression detected: rule {$regressedRule} count increased.";
        }

        $verified = ($policyResult === 'pass' && $syntaxResult !== 'fail' && $rescanResult === 'pass');

        return [
            'verified' => $verified,
            'after_lines' => $afterLines,
            'results' => [
                ['check_type' => 'policy', 'result' => $policyResult, 'log_excerpt' => $policyLog],
                ['check_type' => 'syntax', 'result' => $syntaxResult, 'log_excerpt' => $syntaxLog],
                ['check_type' => 'rescan', 'result' => $rescanResult, 'log_excerpt' => $rescanLog],
            ],
        ];
    }
}
