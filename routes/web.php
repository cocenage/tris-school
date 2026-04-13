<?php

use App\Http\Controllers\TelegramAuthController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('landing.page');
    }

    return redirect()->route(match (auth()->user()->status) {
        'approved' => 'page-home',
        'pending' => 'access.pending',
        'rejected' => 'access.rejected',
        default => 'landing.page',
    });
})->name('landing');

Route::livewire('/login', 'landing')->name('landing.page');

Route::post('/telegram/auth', TelegramAuthController::class)
    ->name('telegram.auth');

Route::livewire('/access/pending', 'access.pending')
    ->name('access.pending');

Route::livewire('/access/rejected', 'access.rejected')
    ->name('access.rejected');

Route::middleware(['auth', 'approved'])->group(function () {
    Route::livewire('/home', 'page-home')->name('page-home');
    Route::livewire('/checks', 'page-checks')->name('page-checks');
    Route::livewire('/applications', 'page-applications')->name('page-applications');
    Route::livewire('/applications/weekend', 'forms.page-weekend')->name('page-applications.weekend');
    Route::livewire('/profile', 'page-profile')->name('page-profile');
    Route::livewire('/profile/calendar', 'profile.page-calendar')->name('page-profile.calendar');
    Route::livewire('/profile/weekend', 'profile.page-weekend')->name('page-profile.weekend');
});

Route::fallback(function () {
    if (! Auth::check()) {
        return redirect()->route('landing');
    }

    $user = Auth::user();

    return redirect()->route(match ($user->status) {
        'approved' => 'page-home',
        'pending' => 'access.pending',
        'rejected' => 'access.rejected',
        default => 'landing',
    });
});