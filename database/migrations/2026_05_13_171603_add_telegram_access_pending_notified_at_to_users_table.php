<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'telegram_access_requested_notified_at')) {
                $table->timestamp('telegram_access_requested_notified_at')->nullable();
            }

            if (!Schema::hasColumn('users', 'telegram_access_pending_notified_at')) {
                $table->timestamp('telegram_access_pending_notified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'telegram_access_requested_notified_at')) {
                $table->dropColumn('telegram_access_requested_notified_at');
            }

            if (Schema::hasColumn('users', 'telegram_access_pending_notified_at')) {
                $table->dropColumn('telegram_access_pending_notified_at');
            }
        });
    }
};