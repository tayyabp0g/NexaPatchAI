<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail. Rows are created, never edited or deleted by the app.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'action', 'entity_type', 'entity_id', 'ip_address', 'metadata'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Short human readable text for dashboards. */
    public function label(): string
    {
        return match ($this->action) {
            'login.success' => 'Logged in',
            'login.failed' => 'Failed login attempt',
            'login.locked' => 'Account temporarily locked',
            'logout' => 'Logged out',
            'user.registered' => 'Account created',
            'user.role_changed' => 'Role changed',
            'project.created' => 'Project created',
            'project.updated' => 'Project updated',
            'project.deleted' => 'Project deleted',
            'project.reviewer_assigned' => 'Reviewer assigned',
            'upload.created' => 'Code uploaded',
            'upload.rejected' => 'Upload rejected',
            'upload.deleted' => 'Upload deleted',
            'scan.started' => 'Scan started',
            'scan.completed' => 'Scan completed',
            'scan.failed' => 'Scan failed',
            'report.downloaded' => 'Report downloaded',
            'finding.status_changed' => 'Finding status changed',
            default => ucfirst(str_replace(['.', '_'], ' ', $this->action)),
        };
    }
}
