<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120);
            $table->string('category', 60)->nullable();
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('serial', 80)->nullable();
            $table->string('serial_normalized', 80)->nullable()->index();
            $table->boolean('is_serialized')->default(false);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('condition', 16)->default('unknown');
            $table->boolean('needs_check')->default(false);
            $table->string('uncertainty', 400)->nullable();
            $table->string('notes', 600)->nullable();
            $table->string('vehicle', 60)->nullable();
            $table->string('status', 16);
            $table->string('read_status', 16)->nullable();
            $table->string('read_message', 40)->nullable();
            $table->foreignId('holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('assigned_on')->nullable();
            $table->string('site', 80)->nullable();
            $table->date('noted_on')->nullable();
            $table->string('source', 120)->nullable();
            $table->foreignId('split_from_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->json('anomaly')->nullable();
            $table->json('pending_request')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deleted_comment', 300)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'holder_id']);
        });

        Schema::create('inventory_item_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->string('kind', 8)->default('photo');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['inventory_item_id', 'removed_at']);
        });

        Schema::create('inventory_attestations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 24)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('delivered_on');
            $table->string('observations', 800)->nullable();
            $table->string('representative_name', 80);
            $table->boolean('technician_signed')->default(false);
            $table->boolean('representative_signed')->default(false);
            $table->string('status', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name', 120)->nullable();
            $table->foreignId('stored_file_id')->constrained()->restrictOnDelete();
            $table->dateTime('created_at');

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('inventory_attestation_item', function (Blueprint $table) {
            $table->foreignId('inventory_attestation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();

            $table->primary(['inventory_attestation_id', 'inventory_item_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            $table->string('label', 120);
            $table->unsignedSmallInteger('quantity')->nullable();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_name', 120)->nullable();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('to_name', 120)->nullable();
            $table->foreignId('holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('holder_name', 120)->nullable();
            $table->string('condition', 16)->nullable();
            $table->string('outcome', 16)->nullable();
            $table->string('kind', 16)->nullable();
            $table->string('comment', 600)->nullable();
            $table->json('details')->nullable();
            $table->dateTime('created_at');

            $table->index('created_at');
            $table->index(['from_user_id', 'to_user_id', 'holder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_attestation_item');
        Schema::dropIfExists('inventory_attestations');
        Schema::dropIfExists('inventory_item_photos');
        Schema::dropIfExists('inventory_items');
    }
};
