<?php

namespace App\Services\Scanner;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ExternalScanner
{
    /**
     * @return array{findings: list<array<string,mixed>>, engines: array<string,string>}
     */
    public function scan(string $sourceDir): array
    {
        if (! config('nexapatch.external_scanners.enabled')) {
            return [
                'findings' => [],
                'engines' => ['semgrep' => 'disabled', 'gitleaks' => 'disabled'],
            ];
        }

        $runner = base_path('engine/semgrep_runner.py');
        if (! is_file($runner)) {
            return [
                'findings' => [],
                'engines' => ['semgrep' => 'failed', 'gitleaks' => 'failed'],
            ];
        }

        $process = new Process([
            (string) config('nexapatch.external_scanners.python_binary'),
            $runner,
            $sourceDir,
        ], base_path(), [
            'SEMGREP_ENABLED' => config('nexapatch.external_scanners.semgrep_enabled') ? 'true' : 'false',
            'GITLEAKS_ENABLED' => config('nexapatch.external_scanners.gitleaks_enabled') ? 'true' : 'false',
            'SEMGREP_CONFIG' => (string) config('nexapatch.external_scanners.semgrep_config'),
            'MAX_FINDINGS' => (string) config('nexapatch.external_scanners.max_findings', 3000),
        ]);
        $process->setTimeout((float) config('nexapatch.external_scanners.timeout', 450));

        try {
            $process->run();
        } catch (ProcessTimedOutException|ProcessStartFailedException) {
            return [
                'findings' => [],
                'engines' => $this->failedEngines(),
            ];
        }

        $result = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($result) || ! is_array($result['engines'] ?? null)) {
            return [
                'findings' => [],
                'engines' => $this->failedEngines(),
            ];
        }

        $engines = [];
        foreach (['semgrep', 'gitleaks'] as $engine) {
            $status = $result['engines'][$engine] ?? 'failed';
            $engines[$engine] = in_array($status, ['completed', 'unavailable', 'disabled', 'failed'], true)
                ? $status
                : 'failed';
        }

        $findings = [];
        foreach ($result['findings'] ?? [] as $finding) {
            if (! is_array($finding) || ! isset($finding['engine'], $finding['file_path'], $finding['rule_id'])) {
                continue;
            }
            if (! in_array($finding['engine'], ['semgrep', 'gitleaks'], true)) {
                continue;
            }
            $findings[] = $finding;
        }

        return ['findings' => $findings, 'engines' => $engines];
    }

    /**
     * @return array<string,string>
     */
    private function failedEngines(): array
    {
        return [
            'semgrep' => config('nexapatch.external_scanners.semgrep_enabled') ? 'failed' : 'disabled',
            'gitleaks' => config('nexapatch.external_scanners.gitleaks_enabled') ? 'failed' : 'disabled',
        ];
    }
}
