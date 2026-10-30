<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('phone', 40)->nullable();
            $table->string('password')->nullable();
            $table->string('legacy_password_hash')->nullable();
            $table->timestamp('legacy_password_expires_at')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->string('locale', 10)->nullable();
            $table->foreignId('agency_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('all_agencies')->default(false);
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_plannable')->default(false);
            $table->boolean('is_hidden_from_planning')->default(false);
            $table->smallInteger('planning_order')->default(0);
            $table->date('departure_date')->nullable();
            $table->boolean('is_available_for_review')->default(false);
            $table->timestamp('anonymised_at')->nullable();
            $table->string('legacy_id', 40)->nullable()->unique();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'is_hidden_from_planning', 'planning_order']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('user_agency_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'starts_on']);
        });

        Schema::create('user_planning_hidden_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_starts_on');

            $table->unique(['user_id', 'week_starts_on']);
        });

        Schema::create('user_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->string('original_name');
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_documents');
        Schema::dropIfExists('user_planning_hidden_weeks');
        Schema::dropIfExists('user_agency_periods');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
