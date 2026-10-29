<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobility_alert_messages', function (Blueprint $table): void {
            $table->string('delivery_status')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->string('error_code')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mobility_alert_messages', function (Blueprint $table): void {
            $table->dropColumn(['delivery_status', 'attempted_at', 'error_code']);
        });
    }
};
