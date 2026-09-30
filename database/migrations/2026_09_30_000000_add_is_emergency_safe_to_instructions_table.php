<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructions', function (Blueprint $table): void {
            $table->boolean('is_emergency_safe')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('instructions', function (Blueprint $table): void {
            $table->dropColumn('is_emergency_safe');
        });
    }
};
