<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    protected $fillable = ['scan_id', 'file_path', 'generated_at'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }
}
