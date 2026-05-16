<?php

use App\Models\Task;
use App\Models\TaskRoom;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_notifications', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Task::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(TaskRoom::class)
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('type');

            $table->string('status')->default('pending');
            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['task_id', 'type']);
            $table->index(['task_room_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_notifications');
    }
};