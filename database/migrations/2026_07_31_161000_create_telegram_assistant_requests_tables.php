<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('analytics');

        $schema->create('telegram_assistant_requests', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id');
            $table->string('telegram_topic_id')->nullable();
            $table->string('telegram_user_id')->nullable();
            $table->unsignedBigInteger('linked_user_id')->nullable();
            $table->unsignedBigInteger('root_message_id');
            $table->string('last_bot_message_id')->nullable();
            $table->string('category', 40)->default('other');
            $table->string('status', 40)->default('received');
            $table->text('original_text')->nullable();
            $table->boolean('is_sensitive')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['telegram_chat_id', 'telegram_user_id', 'status']);
            $table->index(['last_bot_message_id', 'telegram_chat_id']);
            $table->unique(['telegram_chat_id', 'root_message_id']);
        });

        $schema->create('telegram_assistant_request_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('telegram_message_id');
            $table->string('direction', 20)->default('incoming');
            $table->timestamps();

            $table->unique(['request_id', 'telegram_message_id']);
            $table->index('telegram_message_id');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('analytics');
        $schema->dropIfExists('telegram_assistant_request_messages');
        $schema->dropIfExists('telegram_assistant_requests');
    }
};
