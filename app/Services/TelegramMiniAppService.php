<?php

namespace App\Services;

use Illuminate\Support\Arr;

class TelegramMiniAppService
{
    public function validate(string $initData): array
    {
        parse_str($initData, $data);

        $hash = Arr::pull($data, 'hash');

        if (! $hash) {
            abort(403, 'Telegram hash not found.');
        }

        ksort($data);

        $dataCheckString = collect($data)
            ->map(fn ($value, $key) => $key . '=' . $value)
            ->implode("\n");

        $secretKey = hash_hmac(
            'sha256',
            config('services.telegram.bot_token'),
            'WebAppData',
            true
        );

        $calculatedHash = hash_hmac(
            'sha256',
            $dataCheckString,
            $secretKey
        );

        if (! hash_equals($calculatedHash, $hash)) {
            abort(403, 'Telegram auth failed.');
        }

        if (isset($data['auth_date']) && now()->timestamp - (int) $data['auth_date'] > 86400) {
            abort(403, 'Telegram auth expired.');
        }

        $user = isset($data['user']) ? json_decode($data['user'], true) : null;

        if (! is_array($user) || empty($user['id'])) {
            abort(403, 'Telegram user not found.');
        }

        return [
            'telegram_id' => (string) $user['id'],
            'username' => $user['username'] ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'photo_url' => $user['photo_url'] ?? null,
        ];
    }
}