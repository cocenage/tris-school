<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_responses', function (Blueprint $table) {
            $table->unsignedInteger('penalty_points')->default(0)->after('score_percent');
            $table->unsignedInteger('errors_count')->default(0)->after('penalty_points');
            $table->string('result_zone_reason')->nullable()->after('result_zone');
        });
    }

    public function down(): void
    {
        Schema::table('control_responses', function (Blueprint $table) {
            $table->dropColumn([
                'penalty_points',
                'errors_count',
                'result_zone_reason',
            ]);
        });
    }
};