<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            // queued | running | completed | failed
            $table->string('status', 20)->default('queued')->index();
            $table->json('engines_used')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->constrained()->cascadeOnDelete();
            $table->string('rule_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cwe_id', 20)->nullable();
            $table->string('owasp_category', 60)->nullable();
            // critical | high | medium | low | info
            $table->string('severity', 20)->index();
            // confirmed | potential
            $table->string('confidence', 20)->default('potential');
            $table->string('file_path');
            $table->unsignedInteger('line_start')->nullable();
            $table->unsignedInteger('line_end')->nullable();
            $table->text('snippet')->nullable();
            // open | fixed | ignored | false_positive
            $table->string('status', 20)->default('open')->index();
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('findings');
        Schema::dropIfExists('scans');
    }
};
