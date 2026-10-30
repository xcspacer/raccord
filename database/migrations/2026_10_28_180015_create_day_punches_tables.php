<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('day_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->timestamp('start_at');
            $table->decimal('start_latitude', 9, 6)->nullable();
            $table->decimal('start_longitude', 9, 6)->nullable();
            $table->unsignedInteger('start_accuracy')->nullable();
            $table->boolean('start_geo_missing')->default(false);
            $table->string('start_geo_reason', 16)->nullable();
            $table->boolean('start_offline')->default(false);
            $table->timestamp('start_received_at');

            $table->timestamp('end_at')->nullable();
            $table->decimal('end_latitude', 9, 6)->nullable();
            $table->decimal('end_longitude', 9, 6)->nullable();
            $table->unsignedInteger('end_accuracy')->nullable();
            $table->boolean('end_geo_missing')->default(false);
            $table->string('end_geo_reason', 16)->nullable();
            $table->boolean('end_offline')->default(false);
            $table->boolean('end_declared')->default(false);
            $table->timestamp('end_received_at')->nullable();

            $table->timestamp('location_purged_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index('date');
        });

        Schema::create('day_punch_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 16);
            $table->date('date')->nullable();
            $table->boolean('duplicate')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_punch_events');
        Schema::dropIfExists('day_punches');
    }
};
