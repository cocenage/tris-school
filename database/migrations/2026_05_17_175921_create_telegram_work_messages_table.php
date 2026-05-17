<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_work_messages', function (Blueprint $table) {
            $table->id();

            $table->string('chat_id')->index();
            $table->string('chat_title')->nullable();

            $table->unsignedBigInteger('thread_id')->nullable()->index();
            $table->unsignedBigInteger('message_id')->index();

            $table->string('telegram_user_id')->nullable()->index();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            $table->unsignedBigInteger('reply_to_message_id')->nullable()->index();

            $table->text('text')->nullable();
            $table->json('raw')->nullable();

            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_work_messages');
    }
};