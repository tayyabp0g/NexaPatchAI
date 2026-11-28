<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('language', 20)->default('php');
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->timestamps();
            $table->index(['owner_id', 'created_at']);
        });

        // Reviewers assigned to a project (SRS 3.3: reviewers see assigned projects only)
        Schema::create('project_reviewer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });

        // Versions of uploaded code. kind: original (immutable baseline) | patched
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->default('original');
            $table->string('original_name');
            $table->string('storage_path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
            $table->index(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
        Schema::dropIfExists('project_reviewer');
        Schema::dropIfExists('projects');
    }
};
