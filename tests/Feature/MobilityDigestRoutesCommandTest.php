<?php

use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;

it('reports every expected district and incomplete routes without exposing targets or sending messages', function () {
    config([
        'services.telegram.bot_token' => 'test-secret',
        'services.telegram.digest_districts' => [
            'navigli' => ['label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
            'lodi' => ['label' => 'Lodi', 'chat_id' => '-1002', 'latitude' => 45.44, 'longitude' => 9.21],
            'como' => ['label' => 'Como', 'chat_id' => '-1003', 'duty_thread_id' => '33', 'latitude' => 45.80, 'longitude' => 9.08],
            'certosa' => ['label' => 'Certosa', 'chat_id' => '-1004', 'duty_thread_id' => '44', 'latitude' => 120, 'longitude' => 9.13],
        ],
    ]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('mobility:digest-routes'))->toBe(0);
    $output = Artisan::output();

    foreach (['Navigli', 'Lodi', 'Como', 'Certosa', 'Lambrate'] as $district) {
        expect($output)->toContain($district);
    }
    expect($output)->toContain('missing_duty_thread_id', 'invalid_latitude', 'missing_route')
        ->not->toContain('-1001', '-1002', '-1003', '-1004', 'test-secret');
});

it('reports a missing bot token as a local delivery blocker', function () {
    config([
        'services.telegram.bot_token' => null,
        'services.telegram.digest_districts' => [
            'navigli' => ['label' => 'Navigli', 'chat_id' => '-1001', 'duty_thread_id' => '11', 'latitude' => 45.45, 'longitude' => 9.17],
        ],
    ]);

    expect(Artisan::call('mobility:digest-routes'))->toBe(0);
    expect(Artisan::output())->toContain('missing_bot_token');
});
