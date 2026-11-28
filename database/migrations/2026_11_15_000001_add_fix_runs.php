<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One "fix run" = one attempt to repair a scan's findings and build a fixed ZIP.
        Schema::create('fix_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // queued | running | ready (patches proposed, waiting for approval) | completed (ZIP built) | failed
            $table->string('status', 20)->default('queued')->index();
            $table->boolean('use_ai')->default(false);
            $table->unsignedInteger('applied_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('summary')->nullable();
            $table->string('zip_path')->nullable();
            $table->char('zip_sha256', 64)->nullable();
            $table->foreignId('patched_upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            $table->foreignId('after_scan_id')->nullable()->constrained('scans')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('patches', function (Blueprint $table) {
            $table->foreignId('fix_run_id')->nullable()->constrained('fix_runs')->cascadeOnDelete();
            // rules (deterministic fixer) | ai
            $table->string('method', 12)->default('rules');
            // {file, start, remove_count, before_sha, before_display[], add[], rule}
            $table->json('edit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('patches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fix_run_id');
            $table->dropColumn(['method', 'edit']);
        });
        Schema::dropIfExists('fix_runs');
    }
};
