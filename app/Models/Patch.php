<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patch extends Model
{
    protected $fillable = [
        'finding_id', 'fix_run_id', 'diff_text', 'change_summary', 'model_name', 'attempt_no', 'status', 'method', 'edit',
    ];

    protected function casts(): array
    {
        return ['edit' => 'array'];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    public function fixRun(): BelongsTo
    {
        return $this->belongsTo(FixRun::class);
    }

    public function verificationResults(): HasMany
    {
        return $this->hasMany(VerificationResult::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
