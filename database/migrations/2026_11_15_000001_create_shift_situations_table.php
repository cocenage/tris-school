<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_situations', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('district_key');
            // Analytics has its own connection. Keep the stable ledger key without a cross-database FK.
            $table->string('event_key');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('decision')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['date', 'district_key', 'event_key'], 'shift_situation_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_situations');
    }
};
