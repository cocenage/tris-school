<?php

use App\Http\Middleware\EnsureUserIsApproved;
use App\Models\User;
use App\Services\TelegramAuthService;
use Filament\Panel;
use Illuminate\Http\Request;

it('uses the same access decision for work pages and Telegram redirects', function (string $status, bool $active, string $routeName) {
    $user = new User(['status' => $status, 'is_active' => $active, 'role' => 'admin']);
    $request = Request::create('/home');
    $request->setUserResolver(fn () => $user);

    $response = app(EnsureUserIsApproved::class)->handle($request, fn () => response('allowed'));

    expect($user->accessRouteName())->toBe($routeName)
        ->and(app(TelegramAuthService::class)->redirectRouteFor($user))->toBe(route($routeName))
        ->and($user->isApproved())->toBe($routeName === 'page-home');

    if ($routeName === 'page-home') {
        expect($response->getStatusCode())->toBe(200);
    } else {
        expect($response->getStatusCode())->toBe(302)
            ->and($response->headers->get('Location'))->toBe(route($routeName));
    }
})->with([
    ['new', true, 'access.registration'],
    ['new', false, 'access.rejected'],
    ['approved', true, 'page-home'],
    ['approved', false, 'access.rejected'],
    ['pending', true, 'access.pending'],
    ['pending', false, 'access.rejected'],
    ['rejected', true, 'access.rejected'],
    ['rejected', false, 'access.rejected'],
]);

it('denies inactive administrators access to every Filament panel', function () {
    $user = new User(['status' => 'approved', 'is_active' => false, 'role' => 'admin']);

    foreach (['admin', 'education', 'finance'] as $id) {
        expect($user->canAccessPanel(Panel::make()->id($id)))->toBeFalse();
    }
});

it('keeps approved active administrators able to use the admin panel', function () {
    $user = new User(['status' => 'approved', 'is_active' => true, 'role' => 'admin']);

    expect($user->canAccessPanel(Panel::make()->id('admin')))->toBeTrue();
});
