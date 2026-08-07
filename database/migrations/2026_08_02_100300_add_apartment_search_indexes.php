<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apartments', function (Blueprint $table): void {
            $table->index('name', 'apartments_name_index');
            $table->index('address', 'apartments_address_index');
        });
    }

    public function down(): void
    {
        Schema::table('apartments', function (Blueprint $table): void {
            $table->dropIndex('apartments_name_index');
            $table->dropIndex('apartments_address_index');
        });
    }
};
