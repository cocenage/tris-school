<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('tris_id', 8)->nullable()->unique();
        });

        DB::table('users')->whereNull('tris_id')->select('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                do {
                    $id = (string) random_int(10000000, 99999999);
                } while (DB::table('users')->where('tris_id', $id)->exists());

                DB::table('users')->where('id', $user->id)->whereNull('tris_id')->update(['tris_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['tris_id']);
            $table->dropColumn('tris_id');
        });
    }
};
