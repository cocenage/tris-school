<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_request_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();

            $table->string('item_name');
            $table->unsignedInteger('requested_qty')->default(1);
            $table->unsignedInteger('approved_qty')->default(0);

            $table->string('status')->default('pending');
            $table->text('admin_comment')->nullable();

            $table->timestamps();

            $table->index(['inventory_request_id', 'status']);
            $table->index(['inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_request_items');
    }
};