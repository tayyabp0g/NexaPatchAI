<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_DEVELOPER = 'developer';
    public const ROLE_REVIEWER = 'reviewer';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_DEVELOPER, self::ROLE_REVIEWER];

    /**
     * "role" is intentionally NOT mass assignable, so a crafted request can never
     * promote itself to admin. Roles are only set explicitly in code.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->is_active ?? true;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isDeveloper(): bool
    {
        return $this->role === self::ROLE_DEVELOPER;
    }

    public function isReviewer(): bool
    {
        return $this->role === self::ROLE_REVIEWER;
    }

    /** Projects this user owns. */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    /** Projects this user was assigned to as a reviewer. */
    public function reviewingProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_reviewer')->withTimestamps();
    }
}
