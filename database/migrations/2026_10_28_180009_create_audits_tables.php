<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id();
            $table->string('cabinet_key', 60)->unique();
            $table->string('original_cabinet_key', 30)->nullable();
            $table->string('sro', 60)->nullable();
            $table->string('mode', 16)->nullable();
            $table->string('folder', 120)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->decimal('cabinet_latitude', 9, 6)->nullable();
            $table->decimal('cabinet_longitude', 9, 6)->nullable();
            $table->string('cabinet_name', 120)->nullable();
            $table->foreignId('synoptic_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('synoptic_name', 120)->nullable();
            $table->foreignId('kmz_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('kmz_name', 120)->nullable();
            $table->foreignId('finished_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('finished_by_name', 120)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('validated_by_name', 120)->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->string('workflow', 16)->default('standard');
            $table->timestamp('workflow_locked_at')->nullable();
            $table->unsignedTinyInteger('phase')->default(1);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('legacy_key', 40)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deleted_by_name', 120)->nullable();
        });

        Schema::create('audit_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('kind', 8);
            $table->string('type', 120)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('support', 16)->nullable();
            $table->string('support_number', 30)->nullable();
            $table->string('source', 16);
            $table->string('fibre_type', 8)->nullable();
            $table->text('note_route')->nullable();
            $table->text('note_before')->nullable();
            $table->text('note_tco')->nullable();
            $table->string('recheck_state', 16)->nullable();
            $table->string('status', 16)->nullable();
            $table->string('reason', 1000)->nullable();
            $table->foreignId('status_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status_by_name', 120)->nullable();
            $table->timestamp('status_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('confirmed_latitude', 10, 7)->nullable();
            $table->decimal('confirmed_longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('confirmed_accuracy')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmed_by_name', 120)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->json('report_items')->nullable();
            $table->timestamp('report_at')->nullable();
            $table->foreignId('report_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_by_name', 120)->nullable();
            $table->foreignId('pdf_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('pdf_name', 160)->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->string('returned_comment', 1000)->nullable();
            $table->foreignId('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('returned_by_name', 120)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responsible_since')->nullable();
            $table->timestamp('handover_requested_at')->nullable();
            $table->string('inspection_state', 20)->default('to_do');
            $table->string('repair_state', 16)->default('none');
            $table->string('conformity', 24)->default('not_assessed');
            $table->boolean('is_continuation_authorized')->default(false);
            $table->boolean('are_reserves_authorized')->default(false);
            $table->timestamps();

            $table->unique(['audit_id', 'name']);
            $table->index(['responsible_id', 'inspection_state']);
        });

        Schema::create('audit_catalog_items', function (Blueprint $table) {
            $table->id();
            $table->string('list', 12);
            $table->string('code', 40);
            $table->string('label', 160);
            $table->unsignedSmallInteger('position');

            $table->unique(['list', 'code']);
            $table->index(['list', 'position']);
        });

        Schema::create('audit_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('name', 120);
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['audit_id', 'kind']);
        });

        Schema::create('audit_box_rechecks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('draft')->nullable();
            $table->json('conforming_cassettes')->nullable();
            $table->boolean('is_conform')->default(false);
            $table->boolean('has_unsaved_changes')->default(false);
            $table->foreignId('recnok_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('recnok_name', 160)->nullable();
            $table->timestamp('saved_at')->nullable();
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('saved_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('audit_drawer_rechecks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('changes')->nullable();
            $table->json('confirmed')->nullable();
            $table->boolean('has_unsaved_changes')->default(false);
            $table->foreignId('recnok_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('recnok_name', 160)->nullable();
            $table->timestamp('saved_at')->nullable();
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('saved_by_name', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('audit_cables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->string('code', 12);
            $table->boolean('has_no_label')->default(false);
            $table->string('capacity', 8)->nullable();
            $table->string('fibre_type', 8)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['audit_box_id', 'code']);
        });

        Schema::create('audit_control_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('name', 70);
            $table->string('kind', 16)->nullable();
            $table->json('subfolders')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->text('note')->nullable();
            $table->json('report_items')->nullable();
            $table->timestamp('report_at')->nullable();
            $table->foreignId('report_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('report_by_name', 120)->nullable();
            $table->foreignId('pdf_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('pdf_name', 160)->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamps();

            $table->unique(['audit_id', 'name']);
        });

        Schema::create('audit_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_box_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('audit_control_point_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('folder', 24);
            $table->string('subfolder', 16)->default('');
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->string('file_name', 160)->nullable();
            $table->timestamp('captured_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            $table->boolean('is_imported')->default(false);
            $table->boolean('is_geo_missing')->default(false);
            $table->string('geo_missing_reason', 200)->nullable();
            $table->timestamp('received_at');

            $table->index(['audit_box_id', 'folder', 'subfolder']);
            $table->index(['audit_control_point_id', 'folder', 'subfolder']);
        });

        Schema::create('audit_folder_validations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->string('folder', 24);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            $table->unsignedSmallInteger('photos_count');
            $table->timestamp('validated_at');

            $table->unique(['audit_box_id', 'folder']);
        });

        Schema::create('audit_presences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->string('state', 8);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('accuracy')->nullable();
            $table->boolean('is_automatic')->default(false);
            $table->timestamp('since');
            $table->timestamp('seen_at');

            $table->unique(['audit_id', 'user_id']);
        });

        Schema::create('audit_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->string('client_ref', 40)->nullable()->unique();
            $table->string('author_side', 8);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            $table->string('text', 1000);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acknowledged_by_name', 120)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['audit_box_id', 'created_at']);
        });

        Schema::create('audit_applied_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('operation_id', 40);
            $table->string('type', 30);
            $table->char('payload_hash', 64);
            $table->boolean('queued')->default(false);
            $table->timestamp('client_created_at')->nullable();
            $table->integer('clock_gap_seconds')->nullable();
            $table->timestamp('performed_at');
            $table->timestamp('received_at');

            $table->unique(['user_id', 'operation_id']);
            $table->index('received_at');
            $table->index(['user_id', 'performed_at']);
        });

        Schema::create('audit_field_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('field', 120);
            $table->unsignedInteger('revision');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            $table->string('device_id', 40)->nullable();
            $table->timestamp('changed_at');

            $table->unique(['audit_id', 'field']);
        });
    }

    public function down(): void
    {
        foreach (['audit_drawer_rechecks', 'audit_box_rechecks', 'audit_catalog_items', 'audit_plans', 'audit_field_changes', 'audit_applied_operations', 'audit_remarks', 'audit_presences', 'audit_folder_validations', 'audit_photos', 'audit_control_points', 'audit_cables', 'audit_boxes', 'audits'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
