<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacation_request_days', function (Blueprint $table): void {
            $table->text('admin_comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        foreach (['vacation_requests', 'inventory_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('telegram_source_chat_id')->nullable();
                $table->unsignedBigInteger('telegram_source_message_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['vacation_requests', 'inventory_requests'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['telegram_source_chat_id', 'telegram_source_message_id']);
            });
        }
        Schema::table('vacation_request_days', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['admin_comment', 'reviewed_at']);
        });
    }
};
