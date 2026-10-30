<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Companies (sociétés) the staff belong to; their logo and legal details appear on documents.
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 32)->unique();
            $table->text('legal_details')->nullable();
            $table->foreignId('logo_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('legacy_id', 40)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
