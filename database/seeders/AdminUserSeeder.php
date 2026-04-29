<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            [
                'email' => 'zxc@zxc.zxc',
            ],
            [
                'name' => 'Admin',
                'password' => 'zxc',

                'role' => 'admin',
                'status' => 'approved',
                'is_active' => true,

                'approved_at' => now(),
                'approved_by' => null,

                'telegram_id' => null,
                'telegram_username' => null,
                'telegram_first_name' => null,
                'telegram_last_name' => null,
                'telegram_photo_url' => null,
                'telegram_avatar_path' => null,
                'telegram_write_access_granted_at' => null,
                'telegram_last_auth_at' => null,
                'telegram_login_source' => null,
                'telegram_access_approved_notified_at' => null,

                'last_login_at' => null,
                'birthday' => null,
                'work_started_at' => null,
                'dip' => false,
            ]
        );
    }
}