<?php

use App\Models\TaskBoard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_board_columns', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(TaskBoard::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');
            $table->string('slug')->nullable();

            $table->string('color')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_done_column')->default(false);

            $table->timestamps();

            $table->index(['task_board_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_board_columns');
    }
};