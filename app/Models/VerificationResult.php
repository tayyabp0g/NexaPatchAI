<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationResult extends Model
{
    protected $fillable = ['patch_id', 'check_type', 'result', 'log_excerpt'];

    public function patch(): BelongsTo
    {
        return $this->belongsTo(Patch::class);
    }
}
