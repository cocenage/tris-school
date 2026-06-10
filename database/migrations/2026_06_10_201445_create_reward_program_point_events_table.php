<?php

use App\Models\User;
use App\Models\RewardProgram;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_program_point_events', function (Blueprint $table) {
            $table->id();

            $table->foreignIdFor(RewardProgram::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignIdFor(User::class)
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->integer('points');
            $table->string('reason');
            $table->date('event_date')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->timestamps();

            $table->index(['reward_program_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_program_point_events');
    }
};