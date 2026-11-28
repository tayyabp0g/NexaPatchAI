<?php

namespace App\Services\Scanner;

/**
 * Hides secret values before a code snippet is stored or shown (NFR-028).
 */
class Redactor
{
    public static function redact(string $line, bool $aggressive = false): string
    {
        // Known token formats
        $line = preg_replace('/\b(AKIA|AIza|sk_live_|xox[baprs]-|ghp_|sk-(?:proj-)?)[A-Za-z0-9_\-]+/', '$1****', $line) ?? $line;

        // key => "value", key = 'value'
        $line = preg_replace_callback(
            '/((?:password|passwd|pwd|pass|secret|api_?key|apikey|token|private_?key|client_secret|db_pass(?:word)?)["\']?\s*(?:=>|=|:)\s*)(["\'])([^"\']+)\2/i',
            fn ($m) => $m[1].$m[2].self::mask($m[3]).$m[2],
            $line
        ) ?? $line;

        // define('DB_PASSWORD', 'value')
        $line = preg_replace_callback(
            '/(define\s*\(\s*["\'][A-Z_]*(?:PASS|SECRET|KEY|TOKEN)[A-Z_]*["\']\s*,\s*)(["\'])([^"\']+)\2/i',
            fn ($m) => $m[1].$m[2].self::mask($m[3]).$m[2],
            $line
        ) ?? $line;

        // .env style: NAME=value
        $line = preg_replace_callback(
            '/^(\s*[A-Z0-9_]*(?:PASSWORD|SECRET|API_KEY|APP_KEY|TOKEN|PRIVATE_KEY)\s*=\s*)([^\s#"\']+)/',
            fn ($m) => $m[1].self::mask($m[2]),
            $line
        ) ?? $line;

        if ($aggressive) {
            // Connection calls and similar: hide every quoted value of 4+ characters
            $line = preg_replace_callback(
                '/(["\'])([^"\']{4,})\1/',
                fn ($m) => $m[1].self::mask($m[2]).$m[1],
                $line
            ) ?? $line;
        }

        return $line;
    }

    private static function mask(string $value): string
    {
        return substr($value, 0, 2).'****';
    }
}
