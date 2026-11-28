<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upload extends Model
{
    protected $fillable = [
        'project_id', 'kind', 'original_name', 'storage_path', 'sha256', 'size_bytes',
        'uploaded_by', 'original_file', 'is_archive', 'file_count', 'skipped_count', 'extracted_bytes',
    ];

    protected function casts(): array
    {
        return ['is_archive' => 'boolean'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(Scan::class);
    }
}
