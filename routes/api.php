<?php

use App\Http\Controllers\TelegramWorkWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/work-webhook/{secret}', TelegramWorkWebhookController::class)
    ->name('telegram.work.webhook');