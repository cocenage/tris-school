<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('telegram_outbound_operations')
            || Schema::hasColumn('telegram_outbound_operations', 'last_sent_content_hash')) {
            return;
        }

        Schema::table('telegram_outbound_operations', function (Blueprint $table): void {
            $table->char('last_sent_content_hash', 64)->nullable()->after('telegram_message_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('telegram_outbound_operations', 'last_sent_content_hash')) {
            Schema::table('telegram_outbound_operations', function (Blueprint $table): void {
                $table->dropColumn('last_sent_content_hash');
            });
        }
    }
};
