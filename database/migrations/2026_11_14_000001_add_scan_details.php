<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->unsignedInteger('files_scanned')->default(0);
            $table->unsignedInteger('files_skipped')->default(0);
            $table->unsignedSmallInteger('score')->nullable();
            $table->string('grade', 1)->nullable();
            $table->json('summary')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
        });

        Schema::table('findings', function (Blueprint $table) {
            $table->string('engine', 20)->default('static');
            $table->string('category', 40)->nullable()->index();
            $table->text('impact')->nullable();
            $table->text('remediation')->nullable();
            $table->json('references')->nullable();
            $table->string('fingerprint', 40)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            $table->dropColumn(['engine', 'category', 'impact', 'remediation', 'references', 'fingerprint']);
        });
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['files_scanned', 'files_skipped', 'score', 'grade', 'summary', 'duration_ms']);
        });
    }
};
