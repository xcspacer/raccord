<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers of the installation (work sites, audits, client portal and billing share one list).
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->unique();
            $table->boolean('is_active')->default(true);
            $table->string('logo_path')->nullable();
            $table->string('portal_subtitle', 160)->nullable();
            $table->string('locale', 10)->default('fr');
            $table->string('legacy_id', 40)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
