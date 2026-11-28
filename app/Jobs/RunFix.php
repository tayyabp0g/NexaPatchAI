<?php

namespace App\Jobs;

use App\Models\FixRun;
use App\Services\Fixer\FixEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class RunFix implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public int $fixRunId)
    {
    }

    public function handle(FixEngine $engine): void
    {
        $run = FixRun::find($this->fixRunId);
        if (! $run) {
            return;
        }

        try {
            $engine->propose($run);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
