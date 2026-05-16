<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(Task::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->string('title');

            $table->boolean('is_done')->default(false);

            $table->foreignIdFor(User::class, 'done_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('done_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['task_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
    }
};