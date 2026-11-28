<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            // Path of the immutable original, relative to storage_path (e.g. original.zip or source/index.php)
            $table->string('original_file')->default('');
            $table->boolean('is_archive')->default(false);
            $table->unsignedInteger('file_count')->default(1);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedBigInteger('extracted_bytes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
            $table->dropColumn(['original_file', 'is_archive', 'file_count', 'skipped_count', 'extracted_bytes']);
        });
    }
};
