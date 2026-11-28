<?php

namespace App\Services\Fixer;

class LlmFactory
{
    public static function make(): ?LlmClient
    {
        $cfg = config('nexapatch.llm');
        $timeout = (int) ($cfg['timeout'] ?? 60);

        if (($cfg['provider'] ?? 'none') === 'gemini' && ! empty($cfg['gemini_key'])) {
            return new GeminiClient($cfg['gemini_key'], $cfg['gemini_model'], $timeout);
        }
        if (($cfg['provider'] ?? 'none') === 'openai' && ! empty($cfg['base_url'])) {
            $local = str_contains($cfg['base_url'], 'localhost') || str_contains($cfg['base_url'], '127.0.0.1');
            if (! empty($cfg['api_key']) || $local) {
                return new OpenAiClient($cfg['base_url'], $cfg['api_key'] ?? null, $cfg['model'], $timeout);
            }
        }

        return null;
    }

    public static function isConfigured(): bool
    {
        return self::make() !== null;
    }
}
