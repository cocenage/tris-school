<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_calendar_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->boolean('can_view_all_calendars')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_export')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('user_calendar_permissions')
            && DB::table('user_calendar_permissions')->exists()) {
            throw new RuntimeException('Calendar permissions are in use and must be preserved during rollback.');
        }

        Schema::dropIfExists('user_calendar_permissions');
    }
};
