<?php

namespace App\Services\Scanner;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Rule based static security scanner for source code (FR-013..FR-022).
 *
 * It reads files only; it never executes anything. Results are heuristics:
 * "confirmed" means the pattern is a direct match, "potential" means a human should check.
 */
class StaticScanner
{
    public const SCAN_EXTENSIONS = [
        'php', 'phtml', 'inc', 'html', 'htm', 'js', 'json', 'sql', 'ini', 'yml', 'yaml', 'xml', 'env', 'htaccess', 'twig', 'tpl', 'txt',
    ];

    /** @var list<array<string,mixed>> */
    private array $rules;

    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? RuleSet::load();
    }

    /**
     * @return array{findings: list<array<string,mixed>>, files_scanned: int, files_skipped: int}
     */
    public function scan(string $sourceDir): array
    {
        $root = realpath($sourceDir);
        if ($root === false || ! is_dir($root)) {
            return ['findings' => [], 'files_scanned' => 0, 'files_skipped' => 0];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile() && ! $item->isLink()) {
                $paths[] = $item->getPathname();
            }
        }
        sort($paths);

        $maxBytes = (int) config('nexapatch.scan.max_file_kb', 2048) * 1024;
        $findings = [];
        $scanned = 0;
        $skipped = 0;

        foreach ($paths as $path) {
            $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $kind = self::kindOf(basename($path));

            if (! in_array($kind, self::SCAN_EXTENSIONS, true) || filesize($path) > $maxBytes) {
                $skipped++;
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false || str_contains(substr($content, 0, 8192), "\0")) {
                $skipped++;
                continue;
            }

            $scanned++;
            foreach ($this->scanContent($rel, $kind, $content) as $finding) {
                $findings[] = $finding;
            }
        }

        return ['findings' => $findings, 'files_scanned' => $scanned, 'files_skipped' => $skipped];
    }

    /**
     * Scan one file's text. Public so tests and later engines can call it directly.
     *
     * @return list<array<string,mixed>>
     */
    public function scanContent(string $relPath, string $kind, string $content): array
    {
        $active = [];
        foreach ($this->rules as $rule) {
            if ($rule['extensions'] !== null && ! in_array($kind, $rule['extensions'], true)) {
                continue;
            }
            if ($rule['requires_re'] && @preg_match($rule['requires_re'], $content) !== 1) {
                continue;
            }
            if ($rule['unless_re'] && @preg_match($rule['unless_re'], $content) === 1) {
                continue;
            }
            $active[] = $rule;
        }
        if ($active === []) {
            return [];
        }

        $lines = preg_split('/\R/', $content) ?: [];
        $byId = [];
        foreach ($active as $rule) {
            $byId[$rule['id']] = $rule;
        }

        // Pass 1: which rules match which line (an excluded match is remembered, but not reported)
        $raw = [];
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || self::isComment($trimmed, $kind)) {
                continue;
            }
            $subject = strlen($line) > 3000 ? substr($line, 0, 3000) : $line;

            foreach ($active as $rule) {
                foreach ($rule['patterns'] as $pattern) {
                    if (@preg_match($pattern['re'], $subject) === 1) {
                        $excluded = $rule['exclude_re'] && @preg_match($rule['exclude_re'], $subject) === 1;
                        $raw[$index + 1][$rule['id']] = $excluded;
                        break;
                    }
                }
            }
        }

        // Pass 2: apply exclusions and "skip if another rule already explains this line"
        $findings = [];
        foreach ($raw as $lineNo => $matches) {
            foreach ($matches as $ruleId => $excluded) {
                if ($excluded) {
                    continue;
                }
                $rule = $byId[$ruleId];
                $skip = false;
                foreach ($rule['skip_if'] as $other) {
                    if (array_key_exists($other, $matches)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }

                $findings[] = $this->makeFinding($rule, $relPath, $lineNo, $lines);
            }
        }

        return $findings;
    }

    // ---------------------------------------------------------------- helpers

    /** @param list<string> $lines */
    private function makeFinding(array $rule, string $relPath, int $lineNo, array $lines): array
    {
        $from = max(1, $lineNo - 2);
        $to = min(count($lines), $lineNo + 2);
        $snippet = [];
        for ($n = $from; $n <= $to; $n++) {
            $text = rtrim($lines[$n - 1]);
            if (strlen($text) > 300) {
                $text = substr($text, 0, 300).' ...';
            }
            $snippet[] = $n.': '.Redactor::redact($text, (bool) $rule['redact']);
        }

        return [
            'rule_id' => $rule['id'],
            'title' => $rule['title'],
            'description' => $rule['description'],
            'cwe_id' => $rule['cwe'],
            'owasp_category' => $rule['owasp'],
            'severity' => $rule['severity'],
            'confidence' => $rule['confidence'],
            'category' => $rule['category'],
            'impact' => $rule['impact'],
            'remediation' => $rule['remediation'],
            'references' => ReferenceBuilder::for($rule['cwe'], $rule['owasp']),
            'file_path' => $relPath,
            'line_start' => $lineNo,
            'line_end' => $lineNo,
            'snippet' => implode("\n", $snippet),
            'engine' => 'static',
            'fingerprint' => sha1($rule['id'].'|'.$relPath.'|'.$lineNo.'|'.trim($lines[$lineNo - 1])),
        ];
    }

    /** File "kind": the extension, with special names (.env, .htaccess) handled. */
    public static function kindOf(string $filename): string
    {
        $name = strtolower($filename);
        if (str_starts_with($name, '.env')) {
            return 'env';
        }
        if ($name === '.htaccess') {
            return 'htaccess';
        }

        return pathinfo($name, PATHINFO_EXTENSION);
    }

    private static function isComment(string $trimmed, string $kind): bool
    {
        return match (true) {
            in_array($kind, ['php', 'phtml', 'inc'], true) => str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*'),
            $kind === 'js' => str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*'),
            $kind === 'ini' => str_starts_with($trimmed, ';') || str_starts_with($trimmed, '#'),
            default => false,
        };
    }
}
