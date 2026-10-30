<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unique file contents (one row per SHA-256), stored flat on the private disk.
     */
    public function up(): void
    {
        Schema::create('stored_files', function (Blueprint $table) {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->string('disk', 32)->default('local');
            $table->string('path')->unique();
            $table->string('mime_type', 100);
            $table->string('extension', 10);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->unsignedInteger('reference_count')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['reference_count', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stored_files');
    }
};
