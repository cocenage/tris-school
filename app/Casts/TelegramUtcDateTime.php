<?php

namespace App\Casts;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores absolute Telegram/OI instants as timezone-naive UTC database values.
 */
class TelegramUtcDateTime implements CastsAttributes
{
    private const DATABASE_FORMAT = 'Y-m-d H:i:s';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')
            ->setTimezone(config('app.timezone', 'Europe/Rome'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = $value instanceof DateTimeInterface
            ? Carbon::instance($value)
            : (is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value, 'UTC')
                : Carbon::parse((string) $value, config('app.timezone', 'Europe/Rome')));

        return $date->setTimezone('UTC')->format(self::DATABASE_FORMAT);
    }
}
