<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts of the client portal (people of a client company following its audits), the audits each one sees,
     * and the notes clients leave on boxes, control points or a whole audit with their replies.
     */
    public function up(): void
    {
        Schema::create('client_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('username', 60)->unique();
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->boolean('has_all_audits')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('notifications_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_client_account', function (Blueprint $table) {
            $table->foreignId('client_account_id')->constrained('client_accounts')->cascadeOnDelete();
            $table->foreignId('audit_id')->constrained('audits')->cascadeOnDelete();
            $table->primary(['client_account_id', 'audit_id']);
        });

        Schema::create('client_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target', 16);
            $table->foreignId('audit_box_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('audit_control_point_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_label', 80);
            $table->text('body');
            $table->string('author_name', 80);
            $table->string('author_company', 120)->nullable();
            $table->string('status', 10);
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acknowledged_by_name', 120)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['audit_id', 'status']);
        });

        Schema::create('client_remark_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_remark_id')->constrained()->cascadeOnDelete();
            $table->string('author_side', 8);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 120);
            $table->text('body');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_remark_replies');
        Schema::dropIfExists('client_remarks');
        Schema::dropIfExists('audit_client_account');
        Schema::dropIfExists('client_accounts');
    }
};
