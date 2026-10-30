<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Design office workflow (box-by-box validation of an audit by the design office): participants, immutable
 * submissions, the review queue and its single decisions, occurrences (anomalies), repairs, reserves, official
 * dossier versions, versioned documents, positions and synoptic corrections, internal messages and annotations, client claims, AI drafts,
 * pauses and duration corrections, and the head of each hash-chained journal of the activity log.
 *
 * Rules that MySQL cannot express as a partial unique index (« one open item per parent ») use a guard column that
 * the model fills with the parent key only while the row is open, with a unique index on it (a generated column is
 * not allowed on a cascading foreign key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log_chains', function (Blueprint $table) {
            $table->id();
            $table->string('log_name', 64)->unique();
            $table->unsignedBigInteger('last_activity_id')->nullable();
            $table->char('last_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('audit_box_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('joined_at');

            $table->unique(['audit_box_id', 'user_id']);
        });

        Schema::create('audit_decision_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label', 120)->nullable();
            $table->string('submission_kind', 16);
            $table->string('outcome', 32);
            $table->boolean('requires_reason')->default(true);
            $table->boolean('requires_targets')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['submission_kind', 'is_active', 'position']);
        });

        Schema::create('audit_anomaly_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->nullable()->unique();
            $table->string('label', 160)->nullable();
            $table->string('nature', 16);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['nature', 'is_active']);
        });

        Schema::create('audit_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->string('client_operation_id', 64);
            $table->json('snapshot');
            $table->timestamp('received_at');

            $table->unique(['submitted_by', 'client_operation_id']);
            $table->index(['audit_box_id', 'kind']);
        });

        Schema::create('audit_validation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_submission_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->string('state', 16)->default('queued');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at');
            $table->timestamp('deadline_at');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamp('late_at')->nullable();
            $table->timestamp('supervisor_alerted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['state', 'queued_at']);
            $table->index(['assigned_to', 'state']);
        });

        Schema::create('audit_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_validation_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('audit_decision_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('targets')->nullable();
            $table->timestamp('decided_at');
        });

        Schema::create('audit_missing_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_submission_id')->constrained()->cascadeOnDelete();
            $table->string('folder', 24);
            $table->text('justification');
            $table->foreignId('justified_by')->constrained('users')->restrictOnDelete();
            $table->boolean('is_accepted')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('created_at');

            $table->unique(['audit_submission_id', 'folder']);
        });

        Schema::create('audit_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('reference', 60)->unique();
            $table->foreignId('audit_anomaly_type_id')->constrained()->restrictOnDelete();
            $table->string('nature', 16);
            $table->string('state', 20)->default('detected');
            $table->string('source', 16)->default('field');
            $table->text('description');
            $table->string('location_note', 500)->nullable();
            $table->text('action_taken')->nullable();
            $table->text('checks_done')->nullable();
            $table->text('observations')->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->text('pending_justification')->nullable();
            $table->foreignId('pending_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pending_at')->nullable();
            $table->foreignId('pending_authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pending_authorized_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_operation_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['audit_id', 'sequence']);
            $table->unique(['created_by', 'client_operation_id']);
            $table->index(['audit_id', 'state']);
        });

        Schema::create('audit_occurrence_boxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_origin')->default(false);
            $table->boolean('is_suggested')->default(false);
            $table->text('justification')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('added_at');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('excluded_at')->nullable();
            $table->text('exclusion_reason')->nullable();
            $table->string('conformity', 24)->default('non_conforming');
            $table->string('note', 1000)->nullable();

            $table->unique(['audit_occurrence_id', 'audit_box_id']);
            $table->index(['audit_box_id', 'confirmed_at']);
        });

        Schema::create('audit_occurrence_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_photo_id')->constrained()->cascadeOnDelete();
            $table->string('role', 8);
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at');

            $table->unique(['audit_occurrence_id', 'audit_photo_id', 'role'], 'audit_occurrence_photos_unique');
        });

        Schema::create('audit_photo_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_photo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->json('shapes');
            $table->timestamps();
        });

        Schema::create('audit_box_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20)->default('chat');
            $table->text('body');
            $table->boolean('is_blocking')->default(false);
            $table->foreignId('audit_photo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audit_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('client_operation_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['author_id', 'client_operation_id']);
            $table->index(['audit_box_id', 'is_blocking', 'resolved_at']);
        });

        Schema::create('audit_interventions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->date('planned_on');
            $table->foreignId('responsible_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('planned');
            $table->unsignedBigInteger('open_box_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('planned_on');
        });

        Schema::create('audit_intervention_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_intervention_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->unique(['audit_intervention_id', 'user_id']);
        });

        Schema::create('audit_repair_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_decision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_occurrence_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_accepted');
            $table->text('reason')->nullable();

            $table->unique(['audit_decision_id', 'audit_occurrence_id']);
        });

        Schema::create('audit_reserve_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('state', 16)->default('open');
            $table->unsignedBigInteger('open_occurrence_id')->nullable()->unique();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_reserves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_occurrence_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('audit_reserve_proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 24);
            $table->text('reason');
            $table->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('closing_reason')->nullable();
            $table->timestamps();

            $table->index(['audit_box_id', 'closed_at']);
        });

        Schema::create('audit_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedInteger('version_number');
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->string('original_name', 200);
            $table->boolean('is_current')->default(false);
            $table->string('current_key', 40)->nullable()->unique();
            $table->json('summary')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['audit_id', 'kind', 'version_number']);
        });

        Schema::create('audit_box_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('accuracy')->nullable();
            $table->foreignId('audit_document_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['audit_box_id', 'source', 'created_at']);
        });

        Schema::create('audit_synoptic_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_box_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->foreignId('audit_photo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_operation_id', 64);
            $table->string('state', 16)->default('open');
            $table->foreignId('audit_document_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamps();

            $table->unique(['proposed_by', 'client_operation_id'], 'audit_synoptic_corrections_operation_unique');
            $table->index(['audit_id', 'state']);
        });

        Schema::create('audit_dossier_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('kind', 16)->default('initial');
            $table->string('status', 16)->default('pending');
            $table->unsignedBigInteger('in_progress_audit_id')->nullable()->unique();
            $table->text('reason')->nullable();
            $table->string('locale', 10)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->text('error')->nullable();
            $table->json('checks')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['audit_id', 'version_number']);
        });

        Schema::create('audit_dossier_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_dossier_version_id')->constrained()->cascadeOnDelete();
            $table->string('path', 500);
            $table->string('role', 24);
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();

            $table->unique(['audit_dossier_version_id', 'path']);
        });

        Schema::create('audit_reopenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reopened_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('box_ids');
            $table->timestamp('reopened_at');
        });

        Schema::create('audit_client_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('audit_box_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('audit_reserve_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('audit_occurrence_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 24);
            $table->text('body');
            $table->unsignedTinyInteger('round')->default(1);
            $table->string('state', 16)->default('open');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->index(['audit_id', 'state']);
        });

        Schema::create('audit_ai_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_occurrence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('photo_ids');
            $table->string('locale', 10);
            $table->string('model', 80)->nullable();
            $table->text('text');
            $table->string('status', 16)->default('draft');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_pauses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('audit_box_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
        });

        Schema::create('audit_duration_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_box_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('original_seconds');
            $table->unsignedInteger('adjusted_seconds');
            $table->text('justification');
            $table->foreignId('adjusted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach ([
            'audit_duration_adjustments', 'audit_pauses', 'audit_ai_drafts', 'audit_client_claims', 'audit_reopenings', 'audit_dossier_files', 'audit_dossier_versions',
            'audit_box_positions', 'audit_synoptic_corrections', 'audit_document_versions', 'audit_reserves', 'audit_reserve_proposals', 'audit_repair_items', 'audit_intervention_members',
            'audit_interventions', 'audit_box_messages', 'audit_photo_annotations', 'audit_occurrence_photos', 'audit_occurrence_boxes', 'audit_occurrences',
            'audit_missing_proofs', 'audit_decisions', 'audit_validation_requests', 'audit_submissions', 'audit_anomaly_types', 'audit_decision_types',
            'audit_box_participants', 'activity_log_chains',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
