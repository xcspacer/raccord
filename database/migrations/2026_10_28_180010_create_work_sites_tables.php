<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('city', 120);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('crew_count')->default(0);
            $table->string('status', 16)->default('forecast');
            $table->string('project_type', 16)->nullable();
            $table->string('audit_mode', 16)->nullable();
            $table->string('site_manager', 120)->nullable();
            $table->string('cabinet_ref', 60)->nullable();
            $table->foreignId('audit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('legacy_id', 40)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'starts_on', 'ends_on']);
            $table->index('status');
            $table->index('cabinet_ref');
        });

        Schema::create('work_site_user', function (Blueprint $table) {
            $table->foreignId('work_site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('partner');

            $table->primary(['work_site_id', 'user_id']);
        });

        Schema::create('work_site_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_site_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 60);
            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['work_site_id', 'reference']);
        });

        Schema::create('work_site_box_user', function (Blueprint $table) {
            $table->foreignId('work_site_box_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['work_site_box_id', 'user_id']);
        });

        Schema::create('work_site_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_site_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->string('original_name');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['work_site_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_site_attachments');
        Schema::dropIfExists('work_site_box_user');
        Schema::dropIfExists('work_site_boxes');
        Schema::dropIfExists('work_site_user');
        Schema::dropIfExists('work_sites');
    }
};
