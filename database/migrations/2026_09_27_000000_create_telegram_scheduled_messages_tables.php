<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_scheduled_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('control_type', 100)->index();
            // Telegram chats/topics live on the separate analytics connection, so these
            // store their existing record IDs without cross-database foreign keys.
            $table->unsignedBigInteger('telegram_chat_record_id');
            $table->unsignedBigInteger('telegram_topic_record_id')->nullable();
            $table->text('message');
            $table->time('send_time');
            $table->json('weekdays');
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enabled', 'send_time']);
        });

        Schema::create('telegram_scheduled_message_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scheduled_message_id')
                ->constrained('telegram_scheduled_messages')
                ->restrictOnDelete();
            $table->string('control_type', 100);
            $table->string('chat_id')->nullable();
            $table->string('message_thread_id')->nullable();
            $table->dateTime('scheduled_for');
            $table->dateTime('sent_at')->nullable();
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['scheduled_message_id', 'scheduled_for'], 'telegram_scheduled_delivery_once');
            $table->index(['status', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_scheduled_message_deliveries');
        Schema::dropIfExists('telegram_scheduled_messages');
    }
};
