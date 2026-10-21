<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_outbound_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('operation_key', 190)->nullable()->unique();
            $table->string('notification_type', 80);
            $table->string('method', 40);
            $table->string('bot', 20);
            $table->string('chat_id')->nullable();
            $table->string('message_thread_id')->nullable();
            $table->string('district', 40)->nullable();
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->dateTime('scheduled_for')->nullable();
            $table->dateTime('attempted_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->unsignedInteger('error_code')->nullable();
            $table->string('error_reason', 120)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['created_at', 'status']);
            $table->index(['notification_type', 'scheduled_for']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_outbound_operations');
    }
};
