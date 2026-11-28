<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Project extends Model
{
    protected $fillable = ['name', 'description', 'language', 'retention_days'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_reviewer')->withTimestamps();
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(Upload::class);
    }

    public function scans(): HasManyThrough
    {
        return $this->hasManyThrough(Scan::class, Upload::class);
    }

    /**
     * Only projects the given user may see:
     * admin = all, developer = own, reviewer = assigned (plus own, if any).
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('owner_id', $user->id)
              ->orWhereHas('reviewers', fn (Builder $r) => $r->where('users.id', $user->id));
        });
    }
}
