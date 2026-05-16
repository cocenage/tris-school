<?php

use App\Models\TaskBoard;
use App\Models\TaskBoardColumn;
use App\Models\TaskRoom;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(TaskRoom::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(TaskBoard::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(TaskBoardColumn::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->foreignIdFor(User::class, 'created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignIdFor(User::class, 'assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status')->default('new');
            $table->string('priority')->default('normal');

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('deadline_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['task_room_id']);
            $table->index(['task_board_id']);
            $table->index(['task_board_column_id']);
            $table->index(['status', 'deadline_at']);
            $table->index(['assigned_to', 'deadline_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};