<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_outbound_operation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('telegram_outbound_operations')->cascadeOnDelete();
            $table->string('stage', 60);
            $table->string('outcome', 40);
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->index(['operation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_outbound_operation_events');
    }
};
