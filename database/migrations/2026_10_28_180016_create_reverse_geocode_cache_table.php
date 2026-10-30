<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reverse_geocode_cache', function (Blueprint $table) {
            $table->id();
            $table->string('position_key', 40);
            $table->string('locale', 10);
            $table->string('city', 160)->nullable();
            $table->string('region', 160)->nullable();
            $table->string('country', 160)->nullable();
            $table->string('address', 400)->nullable();
            $table->timestamp('checked_at')->index();

            $table->unique(['position_key', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reverse_geocode_cache');
    }
};
