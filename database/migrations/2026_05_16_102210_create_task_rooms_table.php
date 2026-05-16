<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_rooms', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(User::class, 'created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('status')->default('active');

            $table->string('color')->nullable();
            $table->string('icon')->nullable();

            $table->timestamps();

            $table->index(['status']);
        });

        Schema::create('task_room_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_room_id')
                ->constrained('task_rooms')
                ->cascadeOnDelete();

            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('role')->default('member');

            $table->timestamps();

            $table->unique(['task_room_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_room_user');
        Schema::dropIfExists('task_rooms');
    }
};