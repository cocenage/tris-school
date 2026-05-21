<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_chats', function (Blueprint $table) {
            if (! Schema::hasColumn('telegram_chats', 'telegram_chat_id')) {
                $table->string('telegram_chat_id')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('telegram_chats', 'title')) {
                $table->string('title')->nullable()->after('telegram_chat_id');
            }

            if (! Schema::hasColumn('telegram_chats', 'type')) {
                $table->string('type')->nullable()->after('title');
            }

            if (! Schema::hasColumn('telegram_chats', 'is_enabled')) {
                $table->boolean('is_enabled')->default(true)->after('type');
            }
        });
    }

    public function down(): void
    {
        //
    }
};