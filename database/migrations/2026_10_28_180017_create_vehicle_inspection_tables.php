<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 16);
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->char('month', 7);
            $table->dateTime('taken_at');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->json('comparison')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'slot', 'archived_at']);
        });

        Schema::create('vehicle_damages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stored_file_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 32)->nullable();
            $table->string('details', 500)->nullable();
            $table->date('occurred_on');
            $table->timestamps();

            $table->index(['user_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_damages');
        Schema::dropIfExists('vehicle_photos');
    }
};
