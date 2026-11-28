<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixRun extends Model
{
    public const ACTIVE = ['queued', 'running'];

    protected $fillable = [
        'scan_id', 'requested_by', 'status', 'use_ai', 'applied_count', 'skipped_count', 'failed_count',
        'summary', 'zip_path', 'zip_sha256', 'patched_upload_id', 'after_scan_id', 'error_message', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['use_ai' => 'boolean', 'summary' => 'array', 'finished_at' => 'datetime'];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function patches(): HasMany
    {
        return $this->hasMany(Patch::class);
    }

    public function patchedUpload(): BelongsTo
    {
        return $this->belongsTo(Upload::class, 'patched_upload_id');
    }

    public function afterScan(): BelongsTo
    {
        return $this->belongsTo(Scan::class, 'after_scan_id');
    }
}
