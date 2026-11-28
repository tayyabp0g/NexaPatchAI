<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Scan extends Model
{
    public const ACTIVE = ['queued', 'running'];

    protected $fillable = [
        'upload_id', 'started_by', 'status', 'engines_used', 'error_message', 'started_at', 'finished_at',
        'files_scanned', 'files_skipped', 'score', 'grade', 'summary', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'engines_used' => 'array',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    public function fixRuns(): HasMany
    {
        return $this->hasMany(FixRun::class);
    }

    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }
}
