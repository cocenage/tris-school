<?php

use App\Http\Controllers\TelegramAuthController;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'landing')->name('landing');

Route::post('/telegram/auth', TelegramAuthController::class)
    ->name('telegram.auth');

Route::livewire('/access/pending', 'access.pending')
    ->name('access.pending');

Route::livewire('/access/rejected', 'access.rejected')
    ->name('access.rejected');

Route::middleware(['auth', 'approved'])->group(function () {
    Route::livewire('/home', 'page-home')
        ->name('page-home');
});