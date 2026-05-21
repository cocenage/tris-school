<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('instructions', function (Blueprint $table) {
    $table->text('telegram_keywords')->nullable()->after('short_description');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instructions', function (Blueprint $table) {
            //
        });
    }
};
