<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Gérer les accès": per-role switches over the feature catalogue. No row = catalogue default.
     */
    public function up(): void
    {
        Schema::create('role_feature_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 40);
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled');
            $table->timestamps();

            $table->unique(['feature', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_feature_overrides');
    }
};
