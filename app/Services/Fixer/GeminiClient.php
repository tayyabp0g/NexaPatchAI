<?php

namespace App\Services\Fixer;

use App\Exceptions\LlmUnavailable;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Google Gemini via the free tier of Google AI Studio. */
class GeminiClient implements LlmClient
{
    public function __construct(private string $key, private string $model, private int $timeout = 60)
    {
    }

    public function name(): string
    {
        return $this->model;
    }

    public function complete(string $system, string $user): string
    {
        try {
            $caBundle = config('nexapatch.llm.ca_bundle');
            $request = Http::timeout($this->timeout)->acceptJson();
            if (is_string($caBundle) && $caBundle !== '' && is_file($caBundle)) {
                $request = $request->withOptions(['verify' => $caBundle]);
            }

            $response = $request
                ->withHeaders(['x-goog-api-key' => $this->key])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json'],
                ]);
        } catch (Throwable $e) {
            throw new LlmUnavailable('Could not reach the AI service.');
        }

        if (in_array($response->status(), [401, 403, 429, 500, 503], true)) {
            throw new LlmUnavailable('AI service refused or is busy (HTTP '.$response->status().').');
        }
        if (! $response->successful()) {
            throw new LlmUnavailable('AI service error (HTTP '.$response->status().').');
        }

        return (string) ($response->json('candidates.0.content.parts.0.text') ?? '');
    }
}
