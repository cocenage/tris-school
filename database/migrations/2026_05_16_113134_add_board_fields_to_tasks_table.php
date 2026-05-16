<?php

use App\Models\TaskBoard;
use App\Models\TaskBoardColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (! Schema::hasColumn('tasks', 'task_board_id')) {
                $table->foreignIdFor(TaskBoard::class)
                    ->nullable()
                    ->after('task_room_id')
                    ->constrained()
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('tasks', 'task_board_column_id')) {
                $table->foreignIdFor(TaskBoardColumn::class)
                    ->nullable()
                    ->after('task_board_id')
                    ->constrained()
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('tasks', 'sort_order')) {
                $table->unsignedInteger('sort_order')
                    ->default(0)
                    ->after('priority');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'task_board_column_id')) {
                $table->dropConstrainedForeignId('task_board_column_id');
            }

            if (Schema::hasColumn('tasks', 'task_board_id')) {
                $table->dropConstrainedForeignId('task_board_id');
            }

            if (Schema::hasColumn('tasks', 'sort_order')) {
                $table->dropColumn('sort_order');
            }
        });
    }
};