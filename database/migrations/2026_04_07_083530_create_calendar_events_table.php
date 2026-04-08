<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();

            $table->string('type'); // workflow / finance / holiday / peak
            $table->string('title');
            $table->text('description')->nullable();

            $table->date('start_date');
            $table->date('end_date')->nullable();

            $table->string('repeat_type')->default('none'); // none / yearly / monthly / weekly
            $table->date('repeat_until')->nullable();

            $table->boolean('is_active')->default(true);

            $table->unsignedInteger('priority')->default(0);

            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index(['start_date', 'end_date']);
            $table->index('repeat_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};