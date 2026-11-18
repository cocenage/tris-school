<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;

final class TelegramRequestNotSent extends ConnectionException {}
