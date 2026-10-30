<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Media library: a virtual tree of folders (cabinet, then box or the cabinet itself) whose files sit in a
     * « before » or « after » phase; contents are stored once in stored_files.
     */
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('media_folders')->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();

            $table->index(['parent_id', 'name']);
        });

        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_folder_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 8);
            $table->string('file_name');
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploader_name', 120)->nullable();
            $table->dateTime('uploaded_at');
            $table->timestamps();

            $table->unique(['media_folder_id', 'phase', 'file_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
        Schema::dropIfExists('media_folders');
    }
};
