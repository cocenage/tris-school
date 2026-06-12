<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tris_mare_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('employee_external_id')->nullable()->unique();
            $table->string('employee_name');

            $table->integer('daily_points')->default(0);
            $table->integer('weekly_points')->default(0);
            $table->integer('total_points')->default(0);
            $table->integer('left_to_230')->default(0);
            $table->string('status')->nullable();
            $table->integer('progress_percent')->default(0);
            $table->text('comment')->nullable();
            $table->integer('working_days')->default(0);
            $table->integer('rating')->nullable();

            $table->json('daily_history')->nullable();
            $table->json('raw_data')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tris_mare_snapshots');
    }
};