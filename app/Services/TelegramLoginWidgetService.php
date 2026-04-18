<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TelegramLoginWidgetService
{
    public function validate(array $data): array
    {
        $hash = Arr::pull($data, 'hash');

        if (! $hash) {
            throw new HttpException(403, 'Telegram Login Widget hash not found.');
        }

        if (empty($data['id'])) {
            throw new HttpException(403, 'Telegram Login Widget user id not found.');
        }

        if (isset($data['auth_date']) && now()->timestamp - (int) $data['auth_date'] > 86400) {
            throw new HttpException(403, 'Telegram Login Widget auth expired.');
        }

        ksort($data);

        $dataCheckString = collect($data)
            ->filter(fn ($value) => ! is_array($value) && ! is_object($value))
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode("\n");

        $secretKey = hash('sha256', config('services.telegram.bot_token'), true);

        $calculatedHash = hash_hmac(
            'sha256',
            $dataCheckString,
            $secretKey
        );

        if (! hash_equals($calculatedHash, $hash)) {
            throw new HttpException(403, 'Telegram Login Widget auth failed.');
        }

        return [
            'telegram_id' => (string) $data['id'],
            'username' => $data['username'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'photo_url' => $data['photo_url'] ?? null,
            'source' => 'widget',
        ];
    }
}