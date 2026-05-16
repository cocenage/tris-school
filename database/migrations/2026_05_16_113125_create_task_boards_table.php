<?php

use App\Models\TaskRoom;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_boards', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(TaskRoom::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(User::class, 'created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status')->default('active');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['task_room_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_boards');
    }
};