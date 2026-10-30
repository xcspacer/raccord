<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('month', 7);
            $table->string('name', 120);
            $table->boolean('autocrop')->default(true);
            $table->timestamps();
        });

        Schema::create('expense_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('spent_on')->nullable();
            $table->string('nature', 24);
            $table->string('comment')->nullable();
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('merchant', 80)->nullable();
            $table->foreignId('stored_file_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('photo_hash', 64)->nullable();
            $table->string('status', 16);
            $table->string('message')->nullable();
            $table->boolean('duplicate_confirmed')->default(false);
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('expense_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('month', 7);
            $table->string('name', 120);
            $table->unsignedInteger('total_cents');
            $table->unsignedSmallInteger('lines_count');
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->string('file_name')->nullable();
            $table->dateTime('created_at');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_reports');
        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expense_drafts');
    }
};
