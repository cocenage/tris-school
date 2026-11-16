<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_lesson_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instruction_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_opened_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'instruction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_lesson_progress');
    }
};
