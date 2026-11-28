<?php

namespace App\Services\Fixer;

interface LlmClient
{
    /** Send one prompt and return the model's text. Throws LlmUnavailable on rate limits or auth errors. */
    public function complete(string $system, string $user): string;

    public function name(): string;
}
