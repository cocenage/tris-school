<?php

use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalEventEvidence;
use App\Services\Telegram\TelegramApartmentShiftHandoffBuilder;
use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use Tests\Support\TelegramApartmentHandoffFixtures;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    TelegramOperationalTestDatabase::refresh();
    Cache::flush();
    config([
        'services.telegram.evening_intelligence_delivery_enabled' => false,
        'services.telegram.analytics_bot_token' => 'test-analytics-token',
    ]);

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('telegram_id')->nullable();
        $table->timestamps();
    });
    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function () {
    Carbon::setTestNow();
    TelegramOperationalTestDatabase::purge();
    DB::purge('sqlite');
});

it('groups two active attention items into one apartment handoff using editorial evidence', function () {
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via A');
    TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $apartment, messageId: '1');
    TelegramApartmentHandoffFixtures::observe('Не открывается входная дверь.', $apartment, messageId: '2');

    $preview = app(TelegramApartmentShiftHandoffBuilder::class)->build('2026-06-17');

    expect($preview['handoffs'])->toHaveCount(1)
        ->and($preview['handoffs'][0]['item_count'])->toBe(2)
        ->and($preview['handoffs'][0]['destination_chat_id'])->toBe('-1001')
        ->and($preview['handoffs'][0]['destination_thread_id'])->toBe('11')
        ->and($preview['handoffs'][0]['message'])
        ->toContain('У вытяжки не работает свет.')
        ->toContain('Проблема с доступом: дверь была закрыта, никто не открыл.')
        ->toContain('→ Проверить свет у вытяжки.')
        ->toContain('→ Проверить доступ в квартиру.');
});

it('returns no handoff and sends nothing when there are no active attention items', function () {
    $this->mock(\App\Services\Telegram\TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    expect(app(TelegramApartmentShiftHandoffBuilder::class)->build('2026-06-17')['handoffs'])->toBe([])
        ->and(Artisan::call('telegram:apartment-handoff-send', ['--date' => '2026-06-17', '--dry-run' => true]))
        ->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['telegram_actions'])->toBe(0);
});

it('builds independent handoffs for separate apartments and their mapped forum topics', function () {
    $a = TelegramApartmentHandoffFixtures::apartment('Via A');
    $b = TelegramApartmentHandoffFixtures::apartment('Via B');
    TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $a, '-1001', '11', '1');
    TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $b, '-1002', '22', '2');

    $handoffs = app(TelegramApartmentShiftHandoffBuilder::class)->build('2026-06-17')['handoffs'];

    expect($handoffs)->toHaveCount(2)
        ->and($handoffs[0]['apartment_name'])->toBe('Via A')
        ->and($handoffs[0]['destination_chat_id'])->toBe('-1001')
        ->and($handoffs[1]['apartment_name'])->toBe('Via B')
        ->and($handoffs[1]['destination_chat_id'])->toBe('-1002');
});

it('skips an apartment whose explicit apartment context has no mapped Telegram topic', function () {
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via Unmapped');
    [, $event] = TelegramApartmentHandoffFixtures::observe(
        'У вытяжки не работает свет.', $apartment, mapTopic: false,
    );
    $event->update(['apartment_id' => $apartment->id]);

    $preview = app(TelegramApartmentShiftHandoffBuilder::class)->build('2026-06-17');

    expect($preview['handoffs'])->toHaveCount(1)
        ->and($preview['handoffs'][0]['mapping_status'])->toBe('mapping_missing')
        ->and($preview['handoffs'][0]['destination_chat_id'])->toBeNull()
        ->and($preview['handoffs'][0]['message'])->toBeNull();
});

it('fails closed when an apartment has more than one enabled mapped topic', function () {
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via Ambiguous');
    TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $apartment);
    $secondTopicMessage = TelegramOperationalTestDatabase::message(
        'Открывается дверь.', messageId: '2', threadId: '12',
    );
    $secondTopicMessage->topic->update(['apartment_id' => $apartment->id]);

    $handoff = app(TelegramApartmentShiftHandoffBuilder::class)->build('2026-06-17')['handoffs'][0];

    expect($handoff['mapping_status'])->toBe('mapping_ambiguous')
        ->and($handoff['destination_chat_id'])->toBeNull()
        ->and($handoff['message'])->toBeNull();
});

it('renders author and quote from the selected supporting evidence and omits them from action text', function () {
    $employeeId = DB::table('users')->insertGetId([
        'name' => 'Анна', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via A');
    [$message] = TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $apartment);
    $message->telegramUser->update(['linked_user_id' => $employeeId]);

    $handoff = app(TelegramApartmentShiftHandoffBuilder::class)->build('2026-06-17')['handoffs'][0]['message'];
    $actionPosition = strpos($handoff, '→ Проверить свет у вытяжки.');

    expect($handoff)
        ->toContain('👤 Анна')
        ->toContain('💬 «У вытяжки не работает свет.»')
        ->and(substr($handoff, $actionPosition))->not->toContain('👤')->not->toContain('💬');
});

it('omits the author when no linked or existing Telegram ID account match is available', function () {
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via A');
    TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $apartment);

    $message = app(TelegramApartmentShiftHandoffBuilder::class)->build('2026-06-17')['handoffs'][0]['message'];

    expect($message)
        ->toContain('💬 «У вытяжки не работает свет.»')
        ->not->toContain('👤');
});

it('keeps the summary but omits a quote when its linked source text is unavailable', function () {
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via A');
    [$message] = TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $apartment);
    $message->update(['text' => null, 'caption' => null]);

    $handoffs = app(TelegramApartmentShiftHandoffBuilder::class)->build('2026-06-17')['handoffs'];

    expect($handoffs)->toHaveCount(1)
        ->and($handoffs[0]['message'])
        ->toContain('У вытяжки не работает свет.')
        ->not->toContain('💬 «');
});

it('omits a missing next action instead of inventing an arrow line', function () {
    $text = app(\App\Services\Telegram\TelegramApartmentShiftHandoffFormatter::class)->format([
        ['summary' => 'Проверить состояние квартиры.', 'author_name' => null, 'quote' => null, 'next_action' => null],
    ]);

    expect($text)->toContain('Проверить состояние квартиры.')->not->toContain('→');
});

it('sends one message for the apartment and blocks a repeated send for the same date and destination', function () {
    config(['services.telegram.evening_intelligence_delivery_enabled' => true]);
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via A');
    TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $apartment, messageId: '1');
    TelegramApartmentHandoffFixtures::observe('Не открывается входная дверь.', $apartment, messageId: '2');
    $this->mock(\App\Services\Telegram\TelegramBotService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendMessage')->once()
            ->with('-1001', \Mockery::on(fn (string $text): bool => substr_count($text, '→ ') === 2), '11')
            ->andReturn(501);
    });

    $first = Artisan::call('telegram:apartment-handoff-send', ['--date' => '2026-06-17', '--json' => true]);
    $second = Artisan::call('telegram:apartment-handoff-send', ['--date' => '2026-06-17', '--json' => true]);
    $secondPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($first)->toBe(0)
        ->and($second)->toBe(0)
        ->and($secondPayload['results'][0]['status'])->toBe('skipped_duplicate')
        ->and($secondPayload['telegram_actions'])->toBe(0);
});

it('continues to the next apartment after one Telegram delivery failure', function () {
    config(['services.telegram.evening_intelligence_delivery_enabled' => true]);
    $a = TelegramApartmentHandoffFixtures::apartment('Via A');
    $b = TelegramApartmentHandoffFixtures::apartment('Via B');
    TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $a, '-1001', '11', '1');
    TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $b, '-1002', '22', '2');
    $this->mock(\App\Services\Telegram\TelegramBotService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('sendMessage')->twice()->andReturn(null, 502);
    });

    $exit = Artisan::call('telegram:apartment-handoff-send', ['--date' => '2026-06-17', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['results'])->toHaveCount(2)
        ->and($payload['results'][0]['status'])->toBe('failed')
        ->and($payload['results'][1]['status'])->toBe('sent')
        ->and($payload['telegram_actions'])->toBe(1);
});

it('previews without Telegram actions or ledger mutations and leaves the central digest intact', function () {
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via A');
    TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $apartment);
    $eventsBefore = TelegramOperationalEvent::query()->count();
    $evidenceBefore = TelegramOperationalEventEvidence::query()->count();
    $this->mock(\App\Services\Telegram\TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    $exit = Artisan::call('telegram:apartment-handoff-preview', ['--date' => '2026-06-17']);
    $output = Artisan::output();
    $dryRunExit = Artisan::call('telegram:apartment-handoff-send', [
        '--date' => '2026-06-17', '--dry-run' => true, '--json' => true,
    ]);
    $dryRunPayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $central = app(TelegramDigestFormatter::class)->eveningIntelligence(
        app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17'),
    );

    expect($exit)->toBe(0)
        ->and($dryRunExit)->toBe(0)
        ->and($dryRunPayload['telegram_actions'])->toBe(0)
        ->and($output)->toContain('Via A', 'Operations', '🔄 На следующую смену')
        ->toContain('Telegram actions = 0')
        ->and(TelegramOperationalEvent::query()->count())->toBe($eventsBefore)
        ->and(TelegramOperationalEventEvidence::query()->count())->toBe($evidenceBefore)
        ->and($central)->toContain('🔄 Требует внимания:', 'Осталось сделать:')
        ->not->toContain('🔄 На следующую смену');
});

it('excludes an event that is no longer active attention after resolution', function () {
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via A');
    [, $event] = TelegramApartmentHandoffFixtures::observe('У вытяжки не работает свет.', $apartment);
    $event->evidence()->orderByDesc('occurred_at')->orderByDesc('id')->firstOrFail()->update([
        'transition' => 'resolved',
        'status_after' => 'resolved',
        'occurred_at' => '2026-06-17 10:00:00',
    ]);

    expect(app(TelegramApartmentShiftHandoffBuilder::class)->build('2026-06-17')['handoffs'])->toBe([]);
});

it('logs and skips a missing topic mapping without guessing a destination', function () {
    $apartment = TelegramApartmentHandoffFixtures::apartment('Via Unmapped');
    [, $event] = TelegramApartmentHandoffFixtures::observe(
        'У вытяжки не работает свет.', $apartment, mapTopic: false,
    );
    $event->update(['apartment_id' => $apartment->id]);
    config(['services.telegram.evening_intelligence_delivery_enabled' => true]);
    Log::spy();
    $this->mock(\App\Services\Telegram\TelegramBotService::class, fn (MockInterface $mock) => $mock
        ->shouldNotReceive('sendMessage'));

    $exit = Artisan::call('telegram:apartment-handoff-send', ['--date' => '2026-06-17', '--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload['results'][0]['status'])->toBe('skipped_unmapped')
        ->and($payload['results'][0])->not->toHaveKey('destination_thread_id');
    Log::shouldHaveReceived('warning')->with('Apartment handoff skipped: Telegram topic mapping unavailable.', \Mockery::type('array'))->once();
});
