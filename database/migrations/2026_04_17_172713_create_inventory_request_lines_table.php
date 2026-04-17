<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_request_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_request_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('inventory_item_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('item_name');
            $table->string('type_name')->nullable();
            $table->string('size_name')->nullable();
            $table->string('variant_label')->nullable();

            $table->unsignedInteger('requested_qty')->default(0);
            $table->unsignedInteger('issued_qty')->default(0);

            $table->string('status')->default('pending');
            // pending | issued | partially_issued | cancelled

            $table->text('admin_comment')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['inventory_request_id', 'status']);
            $table->index(['inventory_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_request_lines');
    }
};