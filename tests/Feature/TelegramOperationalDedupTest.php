<?php

use App\Models\Apartment;
use App\Models\TelegramOperationalEvent;
use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');

    if (DB::connection('sqlite')->getDatabaseName() !== ':memory:') {
        throw new LogicException('Dedup tests require an in-memory primary database.');
    }

    TelegramOperationalTestDatabase::refresh();
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
});

afterEach(function () {
    TelegramOperationalTestDatabase::purge();
    DB::purge('sqlite');
});

it('merges one employees delay updates and retains its duration, apartment and every source', function () {
    $employeeId = DB::table('users')->insertGetId(['name' => 'Анна', 'created_at' => now(), 'updated_at' => now()]);
    $apartment = Apartment::create(['name' => 'Via X']);
    $observer = app(TelegramOperationalEventObserver::class);
    $messages = [
        TelegramOperationalTestDatabase::message('Я задержусь.', '2026-06-17 08:00:00', '101'),
        TelegramOperationalTestDatabase::message('Буду минут через 10.', '2026-06-17 08:04:00', '102'),
        TelegramOperationalTestDatabase::message('Уже еду.', '2026-06-17 08:06:00', '103'),
    ];
    $messages[0]->telegramUser->update(['linked_user_id' => $employeeId]);
    $messages[0]->topic->update(['apartment_id' => $apartment->id]);

    $outcomes = collect($messages)->map(fn ($message) => $observer->observe(
        $message->fresh(['chat', 'topic', 'telegramUser', 'attachments'])
    )['outcome'])->all();
    $replayed = collect($messages)->map(fn ($message) => $observer->observe($message)['idempotent_reuse'])->all();
    $event = TelegramOperationalEvent::query()->with('evidence.observation.message')->sole();
    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($outcomes)->toBe(['created', 'updated', 'updated'])
        ->and($replayed)->toBe([true, true, true])
        ->and($event->apartment_id)->toBe($apartment->id)
        ->and($event->summary)->toContain('10 минут')
        ->and($event->evidence->pluck('observation.message.message_id')->all())->toBe(['101', '102', '103'])
        ->and($preview['events_included'])->toBe(1)
        ->and($preview['events'][0]['actor_name'])->toBe('Анна')
        ->and($preview['events'][0]['evidence'])->toHaveCount(3)
        ->and($text)->toContain('• Via X — Анна задерживается примерно на 10 минут.')
        ->and(substr_count($text, 'задерживается примерно на 10 минут'))->toBe(1);
});

it('does not merge a delay update from another employee or after a long gap', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $messages = [
        TelegramOperationalTestDatabase::message('Я задержусь.', '2026-06-17 08:00:00', '111', userId: '101'),
        TelegramOperationalTestDatabase::message('Я задержусь на 10 минут.', '2026-06-17 08:05:00', '112', userId: '202'),
        TelegramOperationalTestDatabase::message('Задержка выдачи ключей на 15 минут.', '2026-06-17 08:07:00', '114', userId: '101'),
        TelegramOperationalTestDatabase::message('Я задержусь на 20 минут.', '2026-06-17 10:00:00', '113', userId: '101'),
    ];

    foreach ($messages as $message) {
        $observer->observe($message);
    }

    expect(TelegramOperationalEvent::query()->count())->toBe(4)
        ->and(TelegramOperationalEvent::query()->withCount('evidence')->get()->pluck('evidence_count')->all())
        ->toBe([1, 1, 1, 1]);
});

it('merges one hood-light problem but keeps distinct problems, apartments and reporters separate', function () {
    $viaX = Apartment::create(['name' => 'Via X']);
    $viaY = Apartment::create(['name' => 'Via Y']);
    $observer = app(TelegramOperationalEventObserver::class);
    $messages = [
        TelegramOperationalTestDatabase::message('Не работает свет у вытяжки.', '2026-06-17 08:00:00', '201'),
        TelegramOperationalTestDatabase::message('Да, у вытяжки вообще не включается подсветка.', '2026-06-17 08:05:00', '202'),
        TelegramOperationalTestDatabase::message('Не открывается входная дверь.', '2026-06-17 08:08:00', '203'),
        TelegramOperationalTestDatabase::message('Не работает свет у вытяжки.', '2026-06-17 08:10:00', '204', threadId: '12'),
        TelegramOperationalTestDatabase::message('Не работает свет у вытяжки.', '2026-06-17 08:12:00', '205', userId: '202'),
    ];
    $messages[0]->topic->update(['apartment_id' => $viaX->id]);
    $messages[3]->topic->update(['apartment_id' => $viaY->id]);

    foreach ($messages as $message) {
        $observer->observe($message->fresh(['chat', 'topic', 'telegramUser', 'attachments']));
    }

    $events = TelegramOperationalEvent::query()->withCount('evidence')->orderBy('id')->get();
    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');

    expect($events)->toHaveCount(4)
        ->and($events->pluck('evidence_count')->all())->toBe([2, 1, 1, 1])
        ->and($events->first()->apartment_id)->toBe($viaX->id)
        ->and($events[2]->apartment_id)->toBe($viaY->id)
        ->and($preview['events_included'])->toBe(4)
        ->and($preview['events'][0]['evidence'])->toHaveCount(2);
});

it('reopens a confirmed recurrence rather than hiding it as a duplicate', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message('У вытяжки не работает свет.', '2026-06-17 08:00:00', '301');
    $resolved = TelegramOperationalTestDatabase::message('Исправили.', '2026-06-17 08:10:00', '302',
        ['message' => ['reply_to_message' => ['message_id' => 301]]]);
    $recurrence = TelegramOperationalTestDatabase::message('У вытяжки снова не работает свет.', '2026-06-18 08:00:00', '303');

    $observer->observe($root);
    $observer->observe($resolved);
    $result = $observer->observe($recurrence);
    $event = TelegramOperationalEvent::query()->with('evidence')->sole();

    expect($result['outcome'])->toBe('reopened')
        ->and($event->status)->toBe('reopened')
        ->and($event->evidence->pluck('transition')->all())->toBe(['created', 'resolved', 'reopened']);
});

it('does not merge two unrelated open questions with the same broad subject', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $first = TelegramOperationalTestDatabase::message('Во сколько заезд в квартиру?', '2026-06-17 08:00:00', '401');
    $second = TelegramOperationalTestDatabase::message('Кто встречает гостя в квартире?', '2026-06-17 08:05:00', '402');

    $observer->observe($first);
    $observer->observe($second);

    expect(TelegramOperationalEvent::query()->count())->toBe(2)
        ->and(TelegramOperationalEvent::query()->withCount('evidence')->get()->pluck('evidence_count')->all())
        ->toBe([1, 1]);
});
