<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    /**
     * Record one audit event. Never pass passwords, tokens or source code in $metadata.
     */
    public static function log(string $action, ?Model $entity = null, array $metadata = [], ?int $userId = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'ip_address' => request()?->ip(),
            'metadata' => $metadata ?: null,
        ]);
    }
}
