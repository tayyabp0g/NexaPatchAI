<?php

namespace App\Console\Commands;

use App\Models\Upload;
use App\Services\AuditLogger;
use App\Services\UploadService;
use Illuminate\Console\Command;

class PurgeExpiredUploads extends Command
{
    protected $signature = 'nexapatch:purge-expired';
    protected $description = 'Delete uploads whose project retention period has expired';

    public function handle(UploadService $uploads): int
    {
        $deleted = 0;

        foreach (Upload::with('project')->where('kind', 'original')->get() as $upload) {
            $project = $upload->project;
            if (! $project) {
                continue;
            }

            $expiresAt = $upload->created_at->copy()->addDays((int) $project->retention_days);
            if (now()->lessThan($expiresAt)) {
                continue;
            }

            AuditLogger::log('upload.expired_deleted', $upload, [
                'project_id' => $project->id,
                'retention_days' => $project->retention_days,
            ]);
            $uploads->delete($upload);
            $deleted++;
        }

        $this->info("Purged {$deleted} expired upload(s).");
        return self::SUCCESS;
    }
}
