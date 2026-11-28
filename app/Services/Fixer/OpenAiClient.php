<?php

namespace App\Services\Fixer;

use App\Exceptions\LlmUnavailable;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Any OpenAI-compatible endpoint: Groq, OpenRouter (free models), LM Studio or a local Ollama server. */
class OpenAiClient implements LlmClient
{
    public function __construct(private string $baseUrl, private ?string $key, private string $model, private int $timeout = 60)
    {
    }

    public function name(): string
    {
        return $this->model;
    }

    public function complete(string $system, string $user): string
    {
        try {
            $request = Http::timeout($this->timeout)->acceptJson();
            if ($this->key) {
                $request = $request->withToken($this->key);
            }
            $response = $request->post(rtrim($this->baseUrl, '/').'/chat/completions', [
                'model' => $this->model,
                'temperature' => 0.1,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);
        } catch (Throwable $e) {
            throw new LlmUnavailable('Could not reach the AI service.');
        }

        if (! $response->successful()) {
            throw new LlmUnavailable('AI service refused or is busy (HTTP '.$response->status().').');
        }

        return (string) ($response->json('choices.0.message.content') ?? '');
    }
}
