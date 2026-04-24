<?php

use App\Http\Controllers\LogoutController;
use App\Http\Controllers\TelegramAuthController;
use App\Http\Controllers\TelegramLoginWidgetController;
use App\Http\Controllers\TelegramWriteAccessController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (!auth()->check()) {
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

Route::get('/telegram/login', TelegramLoginWidgetController::class)
    ->name('telegram.login.widget');

Route::post('/telegram/write-access', TelegramWriteAccessController::class)
    ->middleware('auth')
    ->name('telegram.write-access');

Route::post('/logout', LogoutController::class)
    ->middleware('auth')
    ->name('logout');

Route::livewire('/access/pending', 'access.pending')
    ->name('access.pending');

Route::livewire('/access/rejected', 'access.rejected')
    ->name('access.rejected');

Route::middleware(['auth', 'approved'])->group(function () {
    Route::livewire('/home', 'page-home')->name('page-home');
    Route::livewire('/checks', 'page-checks')->name('page-checks');
    Route::livewire('/checks/control', 'forms.page-control')->name('page-checks.control');

    Route::livewire('/applications', 'page-applications')->name('page-applications');
    Route::livewire('/applications/weekend', 'forms.page-weekend')->name('page-applications.weekend');
    Route::livewire('/applications/vacation', 'forms.page-vacation')->name('page-applications.vacation');
    Route::livewire('/applications/inventory', 'forms.page-inventory')->name('page-applications.inventory');
    Route::livewire('/applications/salary', 'forms.page-salary')->name('page-applications.salary');
    Route::livewire('/applications/schedule', 'forms.page-schedule')->name('page-applications.schedule');
    Route::livewire('/applications/feedback', 'forms.page-feedback')->name('page-applications.feedback');

    Route::livewire('/profile', 'page-profile')->name('page-profile');
    Route::livewire('/profile/calendar', 'profile.page-calendar')->name('page-profile.calendar');
    Route::livewire('/profile/checks', 'profile.page-checks')->name('page-profile.checks');
    Route::livewire('/profile/applications', 'profile.page-applications')->name('page-profile.applications');
});

Route::fallback(function () {
    if (!Auth::check()) {
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