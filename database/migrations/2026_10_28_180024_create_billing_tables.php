<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing (« Pointage / Facturation »): billing clients with a generic form type, each person's default client,
     * the clients' intervention types and rates, the shared works catalogue, operators, the technicians' entries
     * with partners, lines and photos, and their expenses with receipts.
     */
    public function up(): void
    {
        Schema::create('billing_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('sheet_label', 31);
            $table->string('works_sheet_label', 31)->nullable();
            $table->string('form_type', 24);
            $table->boolean('has_breakdown')->default(true);
            $table->boolean('has_works')->default(false);
            $table->boolean('has_travel_fee')->default(false);
            $table->string('ticket_pattern', 160)->nullable();
            $table->string('ticket_hint', 160)->nullable();
            $table->string('ticket_example', 40)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('default_billing_client_id')->nullable()->constrained('billing_clients')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('billing_intervention_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_client_id')->constrained()->cascadeOnDelete();
            $table->string('label', 80);
            $table->string('short_label', 40)->nullable();
            $table->string('sheet_label', 31)->nullable();
            $table->boolean('is_billable')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('billing_articles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('label', 255);
            $table->string('unit', 12);
            $table->boolean('is_coefficient')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('billing_operators', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });

        Schema::create('billing_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('rate_key', 60);
            $table->decimal('amount', 10, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['billing_client_id', 'rate_key']);
        });

        Schema::create('billing_rate_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rate_key', 60);
            $table->decimal('old_amount', 10, 2)->nullable();
            $table->decimal('new_amount', 10, 2);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('billing_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('type', 12);
            $table->foreignId('billing_client_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->string('ticket', 60);
            $table->boolean('is_alone')->default(false);
            $table->foreignId('billing_intervention_type_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('level')->nullable();
            $table->string('schedule', 16)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('billing_operator_id')->nullable()->constrained()->nullOnDelete();
            $table->string('closure_status', 8)->nullable();
            $table->text('comment');
            $table->timestamps();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();

            $table->index(['billing_client_id', 'work_date']);
            $table->index(['user_id', 'work_date']);
            $table->index('ticket');
        });

        Schema::create('billing_entry_user', function (Blueprint $table) {
            $table->foreignId('billing_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->primary(['billing_entry_id', 'user_id']);
        });

        Schema::create('billing_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_article_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 10, 2);

            $table->unique(['billing_entry_id', 'billing_article_id']);
        });

        Schema::create('billing_entry_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_entry_id')->constrained()->cascadeOnDelete();
            $table->string('category', 8)->nullable();
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('billing_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('spent_on');
            $table->string('category', 12);
            $table->string('category_other', 120)->nullable();
            $table->decimal('amount', 10, 2);
            $table->decimal('mileage_km', 8, 1)->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 10);
            $table->string('rejection_reason', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'spent_on']);
            $table->index('status');
        });

        Schema::create('billing_expense_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_expense_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['billing_expense_photos', 'billing_expenses', 'billing_entry_photos', 'billing_entry_lines', 'billing_entry_user', 'billing_entries', 'billing_rate_changes', 'billing_rates', 'billing_operators', 'billing_articles', 'billing_intervention_types', 'billing_profiles', 'billing_clients'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
