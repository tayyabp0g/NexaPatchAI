<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->longText('diff_text')->nullable();
            $table->text('change_summary')->nullable();
            $table->string('model_name', 80)->nullable();
            $table->unsignedTinyInteger('attempt_no')->default(1);
            // proposed | verified | unverified | approved | rejected
            $table->string('status', 20)->default('proposed')->index();
            $table->timestamps();
        });

        Schema::create('verification_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patch_id')->constrained()->cascadeOnDelete();
            // syntax | rescan | test
            $table->string('check_type', 20);
            // pass | fail | unavailable
            $table->string('result', 20);
            $table->text('log_excerpt')->nullable();
            $table->timestamps();
            $table->index(['patch_id', 'check_type']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            // approved | rejected
            $table->string('decision', 20);
            $table->text('comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('verification_results');
        Schema::dropIfExists('patches');
    }
};
