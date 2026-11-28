<?php

namespace App\Services\Scanner;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks composer.lock against the public OSV vulnerability database (https://osv.dev).
 * Only package names and versions are sent, never source code. Fails softly when offline.
 */
class OsvScanner
{
    /** @return array{findings: list<array<string,mixed>>, ok: bool} */
    public function scanComposerLock(string $path): array
    {
        $lock = json_decode((string) file_get_contents($path), true);
        if (! is_array($lock)) {
            return ['findings' => [], 'ok' => true];
        }

        $packages = [];
        foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $pkg) {
            $name = $pkg['name'] ?? null;
            $version = isset($pkg['version']) ? ltrim((string) $pkg['version'], 'vV') : null;
            if ($name && $version && ! str_starts_with($version, 'dev-')) {
                $packages[] = ['name' => $name, 'version' => $version];
            }
        }
        $packages = array_slice($packages, 0, 1000);
        if ($packages === []) {
            return ['findings' => [], 'ok' => true];
        }

        $findings = [];
        try {
            foreach (array_chunk($packages, 300) as $chunk) {
                $queries = array_map(fn ($p) => [
                    'package' => ['name' => $p['name'], 'ecosystem' => 'Packagist'],
                    'version' => $p['version'],
                ], $chunk);

                $response = Http::timeout(15)->acceptJson()->post('https://api.osv.dev/v1/querybatch', ['queries' => $queries]);
                if (! $response->successful()) {
                    return ['findings' => $findings, 'ok' => false];
                }

                foreach (($response->json('results') ?? []) as $i => $result) {
                    $vulns = $result['vulns'] ?? [];
                    if ($vulns === [] || ! isset($chunk[$i])) {
                        continue;
                    }
                    $findings[] = $this->makeFinding($chunk[$i], array_column($vulns, 'id'));
                }
            }
        } catch (Throwable) {
            return ['findings' => $findings, 'ok' => false];
        }

        return ['findings' => $findings, 'ok' => true];
    }

    /** @param list<string> $ids */
    private function makeFinding(array $pkg, array $ids): array
    {
        $count = count($ids);
        $shown = array_slice($ids, 0, 8);
        $refs = array_map(fn ($id) => 'https://osv.dev/vulnerability/'.$id, $shown);

        return [
            'rule_id' => 'DEP-OSV-001',
            'title' => "Known vulnerabilities in {$pkg['name']} {$pkg['version']} ({$count})",
            'description' => "The installed version of {$pkg['name']} has {$count} published vulnerability report(s): ".implode(', ', $shown).($count > 8 ? ', ...' : '').'.',
            'cwe_id' => 'CWE-1395',
            'owasp_category' => 'A06:2021 Vulnerable and Outdated Components',
            'severity' => $count >= 3 ? 'high' : 'medium',
            'confidence' => 'confirmed',
            'category' => 'vulnerable_dependency',
            'impact' => 'Attackers can exploit publicly known flaws in this library.',
            'remediation' => "Update the package: composer update {$pkg['name']} (or require a patched version), then re-test the application.",
            'references' => array_merge($refs, ReferenceBuilder::for('CWE-1395', 'A06:2021')),
            'file_path' => 'composer.lock',
            'line_start' => null,
            'line_end' => null,
            'snippet' => null,
            'engine' => 'osv',
            'fingerprint' => sha1('osv|'.$pkg['name'].'|'.$pkg['version']),
        ];
    }
}
