<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('antigoccia_reports', function (Blueprint $table): void {
            // Older reports have no previous-cleaning interval.
            $table->unsignedInteger('days_since_previous_cleaning')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('antigoccia_reports', function (Blueprint $table): void {
            $table->dropColumn('days_since_previous_cleaning');
        });
    }
};
