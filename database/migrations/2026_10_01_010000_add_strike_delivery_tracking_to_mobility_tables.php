<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobility_alerts', function (Blueprint $table): void {
            $table->json('strike_metadata')->nullable();
        });
        Schema::table('mobility_alert_messages', function (Blueprint $table): void {
            $table->string('delivery_key', 64)->nullable()->unique();
            $table->timestamp('queued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mobility_alert_messages', function (Blueprint $table): void {
            $table->dropUnique(['delivery_key']);
            $table->dropColumn(['delivery_key', 'queued_at']);
        });
        Schema::table('mobility_alerts', function (Blueprint $table): void {
            $table->dropColumn('strike_metadata');
        });
    }
};
