<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('focus_v2_media_uploads', function (Blueprint $table): void {
            $table->unsignedBigInteger('control_response_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('focus_v2_media_uploads', function (Blueprint $table): void {
            $table->dropIndex(['control_response_id']);
            $table->dropColumn('control_response_id');
        });
    }
};
