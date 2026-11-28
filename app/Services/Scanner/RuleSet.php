<?php

namespace App\Services\Scanner;

use RuntimeException;

/**
 * Loads the security rules from resources/security/php_rules.json and prepares the regular expressions.
 * Rules are data, not code, so new checks can be added without touching PHP.
 */
class RuleSet
{
    /** @var list<array<string,mixed>>|null */
    private static ?array $cache = null;

    /** @return list<array<string,mixed>> */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = resource_path('security/php_rules.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! isset($data['rules'])) {
            throw new RuntimeException('The security rule file is missing or invalid.');
        }

        $rules = [];
        foreach ($data['rules'] as $rule) {
            foreach ($rule['patterns'] as $i => $pattern) {
                $rule['patterns'][$i]['re'] = self::compile($pattern['regex'], $pattern['flags'] ?? 'i');
            }
            $rule['exclude_re'] = ! empty($rule['exclude']) ? self::compile($rule['exclude'], 'i') : null;
            $rule['unless_re'] = ! empty($rule['file_unless']) ? self::compile($rule['file_unless'], 'is') : null;
            $rule['requires_re'] = ! empty($rule['file_requires']) ? self::compile($rule['file_requires'], 'is') : null;
            $rules[] = $rule;
        }

        return self::$cache = $rules;
    }

    public static function compile(string $regex, string $flags): string
    {
        return '~'.$regex.'~'.$flags;
    }
}
