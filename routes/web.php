<?php

use App\Http\Controllers\LogoutController;
use App\Http\Controllers\TelegramAuthController;
use App\Http\Controllers\TelegramLoginWidgetController;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('page-wishlists');
    }

    return redirect()->route('login');
})->name('landing');

Route::livewire('/login', 'landing')->name('login');

Route::get('/dev-login', function () {
    abort_unless(app()->environment('local'), 404);

    $user = User::query()->updateOrCreate(
        ['email' => 'dev@example.com'],
        [
            'name' => 'Dev User',
            'password' => Hash::make('password'),
            'telegram_id' => 999999999,
            'telegram_username' => 'dev_user',
            'status' => 'approved',
            'is_active' => true,
            'approved_at' => now(),
            'last_login_at' => now(),
        ]
    );

    Auth::login($user, true);
    request()->session()->regenerate();

    return redirect()->route('page-wishlists');
})->name('dev-login');

Route::post('/telegram/auth', TelegramAuthController::class)
    ->name('telegram.auth');

Route::get('/telegram/login', TelegramLoginWidgetController::class)
    ->name('telegram.login.widget');

Route::post('/logout', LogoutController::class)
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Wishli app
|--------------------------------------------------------------------------
| В браузере работает через /dev-login.
| В Telegram работает через tg.auth.
*/

Route::middleware(['tg.auth'])->group(function () {
    Route::livewire('/wishlists', 'page-wishlists')
        ->name('page-wishlists');

    Route::livewire('/wishlists/create', 'page-wishlist-create')
        ->name('page-wishlist-create');

    Route::livewire('/wishlists/{wishlist}', 'page-wishlist-show')
        ->name('page-wishlist-show');

    Route::livewire('/wishlists/{wishlist}/edit', 'page-wishlist-edit')
        ->name('page-wishlist-edit');

    Route::livewire('/wishlists/{wishlist}/items/create', 'page-wishlist-item-create')
        ->name('page-wishlist-item-create');

    Route::livewire('/wishlists/{wishlist}/items/{item}/edit', 'page-wishlist-item-edit')
        ->name('page-wishlist-item-edit');

    Route::livewire('/invite/{token}', 'page-wishlist-invite')
        ->name('page-wishlist-invite');
});

Route::fallback(function () {
    if (auth()->check()) {
        return redirect()->route('page-wishlists');
    }

    return redirect()->route('login');
});