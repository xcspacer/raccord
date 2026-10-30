<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agencies of the installation (legacy "Nord"/"Sud" become data). The planning mode carries the
     * behaviour that the legacy code hard-coded per agency.
     */
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('code', 32)->unique();
            $table->string('planning_mode', 16)->default('detailed');
            $table->foreignId('default_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->unsignedSmallInteger('daily_crew_capacity')->default(4);
            $table->smallInteger('sort_order')->default(0);
            $table->string('legacy_id', 40)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agencies');
    }
};
