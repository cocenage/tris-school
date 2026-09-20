<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->table('telegram_topics', function (Blueprint $table): void {
            // Apartments live in the main database, so this cannot be a cross-connection foreign key.
            $table->unsignedBigInteger('apartment_id')->nullable()->index();
        });

        Schema::connection('analytics')->table('telegram_operational_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('apartment_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('telegram_operational_events', function (Blueprint $table): void {
            $table->dropColumn('apartment_id');
        });

        Schema::connection('analytics')->table('telegram_topics', function (Blueprint $table): void {
            $table->dropColumn('apartment_id');
        });
    }
};
