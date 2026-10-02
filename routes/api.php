<?php

use App\Http\Controllers\TelegramWorkWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/work-webhook/{secret}', TelegramWorkWebhookController::class)
    ->name('telegram.work.webhook');
Route::post('/telegram/scheduled-webhook/{secret}', \App\Http\Controllers\TelegramScheduledWebhookController::class)
    ->name('telegram.scheduled.webhook');
