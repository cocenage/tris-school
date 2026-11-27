<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class TrisIdGenerator
{
    public static function generate(): string
    {
        do {
            $id = (string) random_int(10000000, 99999999);
        } while (DB::table('users')->where('tris_id', $id)->exists());

        // The unique database index is the final guard against concurrent allocation.
        return $id;
    }
}
