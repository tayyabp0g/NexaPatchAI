<?php

namespace App\Services\Fixer;

/**
 * Deterministic, rule-based fixes for single lines. No AI, no tokens, always the same result.
 * Each fixer changes the smallest possible piece of the line and returns null when it is not sure.
 *
 * @phpstan-type Fix array{lines: list<string>, note: string, secrets?: array<string,string>}
 */
class LineFixers
{
    /** Rules that move a secret out of the code (their original values go to a private file in the ZIP). */
    public const SECRET_RULES = ['PHP-SECRET-002', 'PHP-SECRET-004'];

    private const REQ = '\$_(?:GET|POST|REQUEST|COOKIE)\s*\[\s*([\'"])[^\'"\]]+\1\s*\]';

    private const SERVER = '\$_SERVER\s*\[\s*[\'"](?:HTTP_[A-Z_]+|REQUEST_URI|QUERY_STRING|PHP_SELF|PATH_INFO)[\'"]\s*\]';

    public static function supports(string $ruleId): bool
    {
        return method_exists(self::class, self::methodFor($ruleId));
    }

    /** @return array{lines: list<string>, note: string, secrets?: array<string,string>}|null */
    public static function fix(string $ruleId, string $kind, string $line, ?string $prevLine = null): ?array
    {
        $method = self::methodFor($ruleId);
        if (! method_exists(self::class, $method)) {
            return null;
        }

        return self::$method($kind, $line, $prevLine);
    }

    private static function methodFor(string $ruleId): string
    {
        return 'fix_'.strtolower(str_replace('-', '_', $ruleId));
    }

    private static function one(string $line, string $note, array $extra = []): array
    {
        return ['lines' => [$line], 'note' => $note] + $extra;
    }

    // ------------------------------------------------------------------ XSS

    private static function fix_php_xss_001(string $kind, string $line): ?array
    {
        $count = 0;
        $new = preg_replace_callback(
            '/(?<!htmlspecialchars\()(?:'.self::REQ.'|'.self::SERVER.')/',
            fn ($m) => "htmlspecialchars({$m[0]} ?? '', ENT_QUOTES, 'UTF-8')",
            $line,
            -1,
            $count
        );

        return ($count > 0 && $new !== null) ? self::one($new, 'Escaped user input with htmlspecialchars() so it cannot run as script.') : null;
    }

    private static function fix_php_xss_002(string $kind, string $line): ?array
    {
        $count = 0;
        $new = preg_replace(
            '/(?<!htmlspecialchars\()\$_SERVER\s*\[\s*([\'"])PHP_SELF\1\s*\]/',
            "htmlspecialchars(\$_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8')",
            $line,
            -1,
            $count
        );

        return ($count > 0 && $new !== null) ? self::one($new, 'Escaped PHP_SELF with htmlspecialchars().') : null;
    }

    private static function fix_html_link_001(string $kind, string $line): ?array
    {
        $count = 0;
        $new = preg_replace_callback(
            '/target\s*=\s*(["\'])_blank\1/i',
            fn ($m) => $m[0].' rel='.$m[1].'noopener noreferrer'.$m[1],
            $line,
            -1,
            $count
        );

        return ($count > 0 && $new !== null) ? self::one($new, 'Added rel="noopener noreferrer" to the link.') : null;
    }

    // --------------------------------------------------------- configuration

    private static function fix_php_conf_001(string $kind, string $line): ?array
    {
        $call = 'php'.'info';
        if (preg_match('/^(\s*)@?' . preg_quote($call, '/') . '\s*\([^)]*\)\s*;\s*$/i', $line, $m)) {
            return self::one($m[1].'; // call removed by NexaPatch: exposes server details', 'Removed the diagnostic information call.');
        }

        return null;
    }

    private static function fix_php_conf_002(string $kind, string $line): ?array
    {
        $new = preg_replace('/(ini_set\s*\(\s*["\']display_errors["\']\s*,\s*)(?:["\']?(?:1|on|true|stdout)["\']?)(\s*\))/i', "$1'0'$2", $line, -1, $c1);
        if ($c1 > 0) {
            return self::one($new, 'Turned display_errors off.');
        }
        $new = preg_replace('/^(\s*display_errors\s*=\s*)(?:on|1|true|stdout)\b/i', '$1Off', $line, -1, $c2);
        if ($c2 > 0) {
            return self::one($new, 'Set display_errors = Off.');
        }
        $new = preg_replace('/(php_(?:flag|value)\s+display_errors\s+)(?:on|1)/i', '$1off', $line, -1, $c3);
        if ($c3 > 0) {
            return self::one($new, 'Turned display_errors off.');
        }
        $new = preg_replace('/^(\s*APP_DEBUG\s*=\s*)true\b/i', '$1false', $line, -1, $c4);
        if ($c4 > 0) {
            return self::one($new, 'Set APP_DEBUG=false.');
        }

        return null;
    }

    private static function fix_php_conf_003(string $kind, string $line): ?array
    {
        $new = preg_replace('/^(\s*allow_url_include\s*=\s*)(?:on|1|true)\b/i', '$1Off', $line, -1, $c1);
        if ($c1 > 0) {
            return self::one($new, 'Set allow_url_include = Off.');
        }
        $new = preg_replace('/(php_flag\s+allow_url_include\s+)on/i', '$1off', $line, -1, $c2);
        if ($c2 > 0) {
            return self::one($new, 'Set allow_url_include off.');
        }
        $new = preg_replace('/(ini_set\s*\(\s*["\']allow_url_include["\']\s*,\s*)["\']?(?:1|on|true)["\']?/i', "$1'0'", $line, -1, $c3);
        if ($c3 > 0) {
            return self::one($new, 'Set allow_url_include to 0.');
        }

        return null;
    }

    private static function fix_php_conf_005(string $kind, string $line): ?array
    {
        $new = preg_replace('/^(\s*expose_php\s*=\s*)(?:on|1)\b/i', '$1Off', $line, -1, $c1);
        if ($c1 > 0) {
            return self::one($new, 'Set expose_php = Off.');
        }
        $new = preg_replace('/(Options\s+(?:[^#\n]*?\s)?)\+?Indexes\b/i', '$1-Indexes', $line, -1, $c2);
        if ($c2 > 0) {
            return self::one($new, 'Disabled directory listing (-Indexes).');
        }

        return null;
    }

    private static function fix_php_cookie_001(string $kind, string $line): ?array
    {
        $count = 0;
        $new = preg_replace_callback(
            '/\bsetcookie\s*\(\s*([^,;()]+),\s*([^,;()]+)\)/',
            fn ($m) => 'setcookie('.trim($m[1]).', '.trim($m[2])
                .", ['expires' => 0, 'path' => '/', 'secure' => !empty(\$_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax'])",
            $line,
            -1,
            $count
        );

        return ($count > 0 && $new !== null) ? self::one($new, 'Added HttpOnly, SameSite and Secure (on HTTPS) to the cookie.') : null;
    }

    // ------------------------------------------------------------- crypto

    private static function fix_php_crypto_002(string $kind, string $line): ?array
    {
        $total = 0;
        $new = preg_replace('/md5\s*\(\s*uniqid\s*\((?:[^()]|\([^()]*\))*\)\s*\)/', 'bin2hex(random_bytes(16))', $line, -1, $c);
        $total += $c;
        $new = preg_replace('/\buniqid\s*\((?:[^()]|\([^()]*\))*\)/', 'bin2hex(random_bytes(8))', (string) $new, -1, $c);
        $total += $c;
        $new = preg_replace('/\b(?:mt_rand|rand)\s*\(\s*([^(),]+),\s*([^(),]+)\)/', 'random_int($1, $2)', (string) $new, -1, $c);
        $total += $c;
        $new = preg_replace('/\b(?:mt_rand|rand)\s*\(\s*\)/', 'random_int(0, PHP_INT_MAX)', (string) $new, -1, $c);
        $total += $c;

        return $total > 0 ? self::one((string) $new, 'Replaced a predictable random value with random_bytes()/random_int().') : null;
    }

    private static function fix_php_crypto_004(string $kind, string $line): ?array
    {
        $total = 0;
        $new = preg_replace('/(CURLOPT_SSL_VERIFYPEER\s*,\s*)(?:false|0)\b/i', '${1}true', $line, -1, $c);
        $total += $c;
        $new = preg_replace('/(CURLOPT_SSL_VERIFYHOST\s*,\s*)(?:false|0)\b/i', '${1}2', (string) $new, -1, $c);
        $total += $c;
        $new = preg_replace('/(["\']verify_peer(?:_name)?["\']\s*=>\s*)false/i', '${1}true', (string) $new, -1, $c);
        $total += $c;
        $new = preg_replace('/(["\']verify["\']\s*=>\s*)false/i', '${1}true', (string) $new, -1, $c);
        $total += $c;

        return $total > 0 ? self::one((string) $new, 'Turned TLS certificate verification back on.') : null;
    }

    // ------------------------------------------------------------ session

    private static function fix_php_session_001(string $kind, string $line, ?string $prev = null): ?array
    {
        if (! preg_match('/^(\s*)\$_SESSION\s*\[/', $line, $m)) {
            return null;
        }
        // Do not insert a line in front of a brace-less if/else/loop body
        if ($prev !== null && preg_match('/^\s*(?:(?:\}\s*)?else(?:\s*if\b.*\))?|(?:if|elseif|foreach|for|while)\b.*\))\s*$/i', $prev)) {
            return null;
        }

        return ['lines' => [$m[1].'session_regenerate_id(true);', $line], 'note' => 'Added session_regenerate_id(true) before storing the login state.'];
    }

    // -------------------------------------------------------- backdoors

    private static function fix_php_shell_001(string $kind, string $line): ?array
    {
        return self::removeStatement($line);
    }

    private static function fix_php_shell_002(string $kind, string $line): ?array
    {
        return self::removeStatement($line);
    }

    private static function removeStatement(string $line): ?array
    {
        if (preg_match('/^(\s*)@?\s*(?:eval\s*\(|\$_(?:GET|POST|REQUEST|COOKIE)\s*\[).*;\s*$/', $line, $m)) {
            return self::one($m[1].'; // suspected backdoor code removed by NexaPatch', 'Removed a suspected backdoor statement. Investigate how it got there and rotate all passwords.');
        }

        return null;
    }

    // ----------------------------------------------------- files, input

    private static function fix_php_file_001(string $kind, string $line): ?array
    {
        $count = 0;
        $new = preg_replace_callback(
            '/(?<!basename\()'.self::REQ.'/',
            fn ($m) => 'basename('.$m[0].')',
            $line,
            -1,
            $count
        );

        return ($count > 0 && $new !== null)
            ? self::one($new, 'Wrapped the user-supplied path in basename() to block ../ traversal. Also restrict it to an allowed folder.')
            : null;
    }

    private static function fix_php_unser_001(string $kind, string $line): ?array
    {
        return self::unserialize($line); // allowed_classes
    }

    private static function fix_php_unser_002(string $kind, string $line): ?array
    {
        return self::unserialize($line); // allowed_classes
    }

    private static function unserialize(string $line): ?array
    {
        $count = 0;
        $new = preg_replace(
            '/\bunserialize\s*\(\s*(\$[A-Za-z_]\w*(?:\s*\[[^\]]*\])*)\s*\)/',
            "unserialize($1, ['allowed_classes' => false])",
            $line,
            -1,
            $count
        );

        return ($count > 0 && $new !== null) ? self::one($new, 'unserialize() no longer creates objects (allowed_classes => false).') : null;
    }

    private static function fix_php_varow_001(string $kind, string $line): ?array
    {
        $count = 0;
        $new = preg_replace('/\bextract\s*\(\s*(\$_(?:GET|POST|REQUEST|COOKIE))\s*\)/', 'extract($1, EXTR_SKIP)', $line, -1, $count);

        return ($count > 0 && $new !== null) ? self::one($new, 'extract() can no longer overwrite existing variables (EXTR_SKIP). Prefer reading specific keys.') : null;
    }

    // ----------------------------------------------------------- secrets

    private static function fix_php_secret_002(string $kind, string $line): ?array
    {
        if (! in_array($kind, ['php', 'phtml', 'inc'], true)) {
            return null;
        }

        $secrets = [];
        $keyword = '/(?:password|passwd|pwd|secret|api_?key|apikey|token|private_?key|client_secret|\bpass\b)/i';

        $new = preg_replace_callback(
            '/(\$?[A-Za-z_][A-Za-z0-9_]*|["\'][A-Za-z_][A-Za-z0-9_]*["\'])(\s*(?:=>|=)\s*)(["\'])([^"\'\s$]{6,})\3/',
            function ($m) use (&$secrets, $keyword) {
                $name = trim($m[1], "\$\"'");
                if (! preg_match($keyword, $name)) {
                    return $m[0];
                }
                $env = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));
                $secrets[$env] = $m[4];

                return $m[1].$m[2]."getenv('{$env}') ?: ''";
            },
            $line
        );

        $new = preg_replace_callback(
            '/(define\s*\(\s*(["\'])([A-Z_]*(?:PASS|SECRET|KEY|TOKEN)[A-Z_]*)\2\s*,\s*)(["\'])([^"\']{4,})\4(\s*\))/i',
            function ($m) use (&$secrets) {
                $secrets[strtoupper($m[3])] = $m[5];

                return $m[1]."getenv('".strtoupper($m[3])."') ?: ''".$m[6];
            },
            (string) $new
        );

        return $secrets !== []
            ? self::one((string) $new, 'Removed the hard-coded secret from the code; it is read from an environment variable instead.', ['secrets' => $secrets])
            : null;
    }

    private static function fix_php_secret_004(string $kind, string $line): ?array
    {
        $secrets = [];
        $new = preg_replace_callback(
            '/\b(mysqli_connect|mysql_connect|pg_connect|new\s+mysqli|new\s+PDO)\s*\(\s*(["\'][^"\']*["\']\s*,\s*["\'][^"\']*["\']\s*,\s*)(["\'])([^"\']{3,})\3/i',
            function ($m) use (&$secrets) {
                $secrets['DB_PASSWORD'] = $m[4];

                return $m[1].'('.$m[2]."getenv('DB_PASSWORD') ?: ''";
            },
            $line
        );

        return $secrets !== []
            ? self::one((string) $new, 'The database password is now read from the DB_PASSWORD environment variable.', ['secrets' => $secrets])
            : null;
    }

    // ----------------------------------------------------------- SQLi & XML & Redirect

    private static function fix_php_sqli_001(string $kind, string $line): ?array
    {
        // For ID/numeric lookups: safe integer cast
        $count = 0;
        $new = preg_replace_callback(
            '/(id\s*=\s*[\'"]?\s*\.?\s*)('.self::REQ.')/i',
            fn ($m) => $m[1].'(int)('.$m[2]." ?? 0)",
            $line,
            -1,
            $count
        );

        if ($count > 0 && $new !== null) {
            return self::one($new, 'Cast the numeric ID to (int) to prevent SQL injection.');
        }

        return null;
    }

    private static function fix_php_redirect_001(string $kind, string $line): ?array
    {
        $count = 0;
        $new = preg_replace_callback(
            '/\bheader\s*\(\s*(["\'])\s*Location\s*:\s*\1\s*\.\s*('.self::REQ.')\s*\)/i',
            fn ($m) => 'header('.$m[1].'Location: '.$m[1].' . (filter_var('.$m[2]." ?? '', FILTER_VALIDATE_URL) ? ".$m[2]." : '/'))",
            $line,
            -1,
            $count
        );

        return ($count > 0 && $new !== null) ? self::one($new, 'Validated the redirect URL with filter_var() to prevent open redirect.') : null;
    }

    private static function fix_php_xxe_001(string $kind, string $line): ?array
    {
        $count = 0;
        $new = preg_replace('/\blibxml_disable_entity_loader\s*\(\s*false\s*\)/i', 'libxml_disable_entity_loader(true)', $line, -1, $c1);
        $count += $c1;
        $new = preg_replace('/\bLIBXML_NOENT\b/', '0', (string) $new, -1, $c2);
        $count += $c2;
        $new = preg_replace('/\bLIBXML_DTDLOAD\b/', '0', (string) $new, -1, $c3);
        $count += $c3;

        return ($count > 0 && $new !== null) ? self::one((string) $new, 'Disabled XML external entity loading.') : null;
    }

    private static function fix_php_csrf_001(string $kind, string $line): ?array
    {
        $count = 0;
        $new = preg_replace_callback(
            '/(<form\b[^>]*method=[\'"]post[\'"][^>]*>)/i',
            fn ($m) => $m[1]."\n    <input type=\"hidden\" name=\"csrf_token\" value=\"<?= htmlspecialchars(\$_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>\">",
            $line,
            -1,
            $count
        );

        return ($count > 0 && $new !== null) ? self::one($new, 'Added CSRF token input field to POST form.') : null;
    }
}
