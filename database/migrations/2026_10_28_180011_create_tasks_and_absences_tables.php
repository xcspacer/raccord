<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('state_code', 8)->default('HO');
            $table->string('label', 60)->nullable();
            $table->string('detail')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('work_site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('completion_status', 16)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('not_done_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('legacy_id', 40)->nullable()->unique();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index('date');
        });

        Schema::create('absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
        Schema::dropIfExists('tasks');
    }
};
