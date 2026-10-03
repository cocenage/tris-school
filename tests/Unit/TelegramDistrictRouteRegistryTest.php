<?php

use App\Services\Telegram\TelegramDistrictRouteRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('returns only complete validated district routes', function () {
    config(['services.telegram.digest_districts' => [
        'navigli' => [
            'label' => 'Navigli',
            'chat_id' => '-1001',
            'duty_thread_id' => '11',
            'latitude' => 45.45,
            'longitude' => 9.17,
        ],
        'broken' => [
            'label' => 'Broken',
            'chat_id' => '-1002',
            'latitude' => 120,
            'longitude' => 9.2,
        ],
    ]]);

    $registry = app(TelegramDistrictRouteRegistry::class);

    expect($registry->routes())->toHaveCount(1)
        ->and($registry->find('NAVIGLI'))->toMatchArray([
            'key' => 'navigli',
            'label' => 'Navigli',
            'chat_id' => '-1001',
            'duty_thread_id' => '11',
            'latitude' => 45.45,
            'longitude' => 9.17,
            'valid' => true,
            'errors' => [],
        ])
        ->and($registry->find('broken'))->toBeNull();

    $broken = $registry->diagnostics()->firstWhere('key', 'broken');

    expect($broken['valid'])->toBeFalse()
        ->and($broken['errors'])->toContain('missing_duty_thread_id', 'invalid_latitude');
});

it('keeps evening delivery disabled by default', function () {
    expect((bool) config('services.telegram.evening_intelligence_delivery_enabled', false))->toBeFalse();
});

it('unites explicit supervisors and district source chats without requiring a duty thread', function () {
    config([
        'services.telegram.operational_chat_ids' => ['-1008', '-1001'],
        'services.telegram.digest_districts' => [
            'navigli' => ['label' => 'Navigli', 'chat_id' => '-1001', 'latitude' => 45.45, 'longitude' => 9.17],
            'como' => ['label' => 'Como', 'chat_id' => '-1003', 'latitude' => 45.80, 'longitude' => 9.08],
            'broken' => ['label' => 'Broken', 'chat_id' => '-1004', 'latitude' => 120, 'longitude' => 9.08],
        ],
    ]);

    $registry = app(TelegramDistrictRouteRegistry::class);

    expect($registry->operationalChatIds())->toBe(['-1008', '-1001', '-1003'])
        ->and($registry->isOperationalChatId('-1003'))->toBeTrue()
        ->and($registry->isOperationalChatId('-1004'))->toBeFalse();
});
