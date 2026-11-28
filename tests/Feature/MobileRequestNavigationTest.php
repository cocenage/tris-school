<?php

use App\Http\Middleware\SafeguardRolePreviewData;
use Illuminate\Support\Facades\Route;

it('keeps the canonical requests page and legacy history link protected', function () {
    $routes = Route::getRoutes();
    foreach (['page-applications' => 'applications', 'page-profile.applications' => 'profile/applications'] as $name => $uri) {
        $route = $routes->getByName($name);
        expect($route->uri())->toBe($uri)
            ->and($route->gatherMiddleware())->toContain('auth', 'approved', SafeguardRolePreviewData::class);
    }
});

it('preserves all six request creation destinations', function () {
    foreach (['inventory', 'salary', 'weekend', 'vacation', 'schedule', 'feedback'] as $type) {
        expect(route('page-applications.'.$type, [], false))->toBe('/applications/'.$type);
    }
});
