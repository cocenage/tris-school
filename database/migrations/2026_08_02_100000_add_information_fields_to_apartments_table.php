<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apartments', function (Blueprint $table): void {
            $table->string('information_status')->default('published')->index();
            $table->timestamp('information_updated_at')->nullable();
            $table->foreignId('information_updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('apartments', function (Blueprint $table): void {
            $table->dropForeign(['information_updated_by']);
            $table->dropColumn(['information_status', 'information_updated_at', 'information_updated_by']);
        });
    }
};
