<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobility_alert_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('mobility_alert_id')
                ->nullable()
                ->constrained('mobility_alerts')
                ->nullOnDelete();

            $table->string('message_type')->default('worker_digest');

            $table->string('chat_id');
            $table->string('thread_id')->nullable();
            $table->string('telegram_message_id');

            $table->text('text')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            $table->timestamps();

            $table->index(['message_type', 'sent_at']);
            $table->index(['chat_id', 'thread_id']);
            $table->index('telegram_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobility_alert_messages');
    }
};