<?php

use App\Models\TelegramOperationalEvent;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-16 21:00:00', 'Europe/Rome'));
    TelegramOperationalTestDatabase::refresh();
    config([
        'services.telegram.evening_intelligence_delivery_mode' => 'centralized',
        'services.telegram.evening_intelligence_delivery_enabled' => false,
        'services.telegram.digest_districts' => collect(['navigli', 'lodi', 'lambrate', 'como', 'certosa'])
            ->mapWithKeys(fn (string $key, int $index): array => [$key => [
                'label' => ucfirst($key), 'chat_id' => (string) (-1001 - $index),
                'duty_thread_id' => null, 'latitude' => 45.45, 'longitude' => 9.17,
            ]])->all(),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    TelegramOperationalTestDatabase::purge();
});

function managementMessage(string $text, string $id, string $time, string $chat = '-1001', array $raw = []): void
{
    $message = TelegramOperationalTestDatabase::message($text, $time, $id, $raw, chatId: $chat, threadId: '12');
    $message->topic->update(['title' => 'Via Test 8']);
    app(TelegramOperationalEventObserver::class)->observe($message);
}

it('dry-runs five source-linked district reports with faults, instruction questions, praise and linen', function () {
    managementMessage('Не работает замок на двери.', '101', '2026-10-15 09:00:00');
    managementMessage('Как включить вытяжку в этой квартире?', '102', '2026-10-15 10:00:00');
    managementMessage('Я заранее обнаружила брак 2 полотенец до заезда и сразу сообщила.', '103', '2026-10-15 11:00:00');
    managementMessage('Не работает свет в ванной.', '104', '2026-10-14 10:00:00');
    managementMessage('Брак 5 простыней с дырками.', '105', '2026-10-15 20:30:00');

    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--dry-run' => true,
    ]))->toBe(0);
    $output = Artisan::output();

    foreach (['Navigli', 'Lodi', 'Lambrate', 'Como', 'Certosa'] as $district) {
        expect($output)->toContain('🌙 '.$district.' — оперативные итоги дня · 15.10.2026');
    }
    expect($output)->toContain('Технические неисправности', 'Значимые нерешённые проблемы',
        'Вопросы клинеров и инструкции', 'Поводы для похвалы', 'Брак белья',
        'Подтверждено: 2 шт.', '2 × полотенце', 'https://t.me/c/')
        ->not->toContain('5 × простыня', 'Не работает свет в ванной.');
});

it('shows sourced follow-up context without treating a promised visit as a resolution', function () {
    managementMessage('Не работает замок на двери.', '501', '2026-10-15 09:00:00');
    managementMessage('Мастера вызвали, завтра приедет.', '502', '2026-10-15 11:00:00', raw: [
        'message' => ['reply_to_message' => ['message_id' => 501]],
    ]);
    expect(TelegramOperationalEvent::query()->sole()->evidence()->count())->toBe(2);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--district' => 'navigli', '--dry-run' => true,
    ]))->toBe(0);
    expect(Artisan::output())->toContain('Что произошло:', 'Мастера вызвали', '/501', '/502', 'решение не подтверждено');
});

it('shows a resolved technical fault as a reported fault with its confirmed resolution', function () {
    managementMessage('Не работает замок на двери.', '511', '2026-10-15 09:00:00');
    managementMessage('Починили замок на двери.', '512', '2026-10-15 11:00:00', raw: [
        'message' => ['reply_to_message' => ['message_id' => 511]],
    ]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--district' => 'navigli', '--dry-run' => true,
    ]))->toBe(0);
    expect(Artisan::output())->toContain('Технические неисправности', 'Не работает замок', 'Починили замок', 'Статус: решено')
        ->not->toContain('Значимые нерешённые проблемы');
});

it('does not relabel an old unresolved fault as first reported today after a repeat report', function () {
    managementMessage('Не работает замок на двери.', '201', '2026-10-14 09:00:00');
    managementMessage('Не работает замок на двери, всё ещё не починили.', '202', '2026-10-15 09:00:00');
    expect(TelegramOperationalEvent::query()->count())->toBe(1);

    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));
    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--district' => 'navigli', '--dry-run' => true,
    ]))->toBe(0);
    expect(Artisan::output())->not->toContain('Значимые нерешённые проблемы', 'Технические неисправности');
});

it('keeps a partial dirty-linen pickup unresolved', function () {
    managementMessage('Курьер забрал только 2 из 5 комплектов грязного белья.', '301', '2026-10-15 18:00:00');
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--district' => 'navigli', '--dry-run' => true,
    ]))->toBe(0);
    expect(Artisan::output())->toContain('Значимые нерешённые проблемы', '2 из 5', 'решение не подтверждено');
});

it('keeps only supported physical faults and actionable problems in management sections', function () {
    managementMessage('В выходные консьерж не работает.', '701', '2026-10-15 08:00:00');
    managementMessage('Полотенце сломано.', '702', '2026-10-15 09:00:00');
    managementMessage('Она пластиковая, боюсь сломать.', '703', '2026-10-15 10:00:00');
    managementMessage('Курьер забрал грязное.', '704', '2026-10-15 11:00:00');
    managementMessage('Сломано.', '705', '2026-10-15 12:00:00');
    managementMessage('Сломана, уже довольно давно.', '706', '2026-10-15 13:00:00');
    managementMessage('Не работает чайник.', '707', '2026-10-15 14:00:00');

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-10-15', [
        'district' => ['key' => 'navigli', 'label' => 'Navigli', 'chat_id' => '-1001'],
    ]);
    $sections = $preview['management_sections'];
    $faultSummaries = collect($sections['technical_faults'])->pluck('summary');
    $problemSummaries = collect($sections['unresolved_problems'])->pluck('summary');

    expect($faultSummaries)->toContain('Не работает чайник.')
        ->and($faultSummaries)->toHaveCount(1)
        ->and($problemSummaries)->toContain('Не работает чайник.')
        ->and(collect($sections['technical_faults'])->pluck('location')->unique()->all())->toBe(['Via Test 8'])
        ->and(collect($sections['technical_faults'])->pluck('source_url')->every(fn ($url) => filled($url)))->toBeTrue()
        ->and(collect($sections['unresolved_problems'])->pluck('summary'))
        ->not->toContain('Курьер забрал грязное.', 'Сломано.', 'Сломана, уже довольно давно.');
});

it('does not present a broken appliance question as an instruction gap', function () {
    managementMessage('Как исправить сломанную вытяжку в квартире?', '401', '2026-10-15 10:00:00');
    expect(TelegramOperationalEvent::query()->count())->toBe(1);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--district' => 'navigli', '--dry-run' => true,
    ]))->toBe(0);
    expect(Artisan::output())->not->toContain('Вопросы клинеров и инструкции');
});

it('keeps known findings visible while warning that another message is unprocessed', function () {
    managementMessage('Не работает замок на двери.', '601', '2026-10-15 09:00:00');
    TelegramOperationalTestDatabase::message('Нужно проверить свет.', '2026-10-15 10:00:00', '602', chatId: '-1001');
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--district' => 'navigli', '--dry-run' => true,
    ]))->toBe(0);
    expect(Artisan::output())->toContain('Не работает замок', 'обработаны не полностью')
        ->not->toContain('значимых выводов не выявлено');
});

it('reports a missing configured district source instead of silently claiming five previews', function () {
    $routes = config('services.telegram.digest_districts');
    $routes['certosa']['chat_id'] = null;
    config(['services.telegram.digest_districts' => $routes]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--dry-run' => true, '--json' => true,
    ]))->toBe(1);
    $results = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'];
    expect($results)->toHaveCount(5)
        ->and(collect($results)->firstWhere('district', 'certosa')['status'])->toBe('failed');
});

it('reports a district omitted from configuration as a missing source', function () {
    $routes = config('services.telegram.digest_districts');
    unset($routes['certosa']);
    config(['services.telegram.digest_districts' => $routes]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--dry-run' => true, '--json' => true,
    ]))->toBe(1);
    $results = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'];
    expect($results)->toHaveCount(5)
        ->and(collect($results)->firstWhere('district', 'certosa')['reason'])->toBe('source_route_incomplete');
});

it('does not add unrelated registry destinations to the five district report', function () {
    $routes = config('services.telegram.digest_districts');
    $routes['other'] = ['label' => 'Other', 'chat_id' => '-1010', 'duty_thread_id' => null,
        'latitude' => 45.45, 'longitude' => 9.17];
    config(['services.telegram.digest_districts' => $routes]);
    $this->mock(TelegramBotService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sendMessage'));

    expect(Artisan::call('telegram:evening-intelligence-send', [
        '--date' => '2026-10-15', '--dry-run' => true, '--json' => true,
    ]))->toBe(0);
    $results = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'];
    expect(array_column($results, 'district'))->toBe(['navigli', 'lodi', 'lambrate', 'como', 'certosa']);
});

it('keeps the standalone linen preview command but does not schedule a second linen delivery', function () {
    expect(Artisan::call('schedule:list'))->toBe(0);
    expect(Artisan::output())->toContain('telegram:evening-intelligence-send')
        ->not->toContain('telegram:linen-defects-report');
});
