<?php

use App\Models\Apartment;
use App\Models\TelegramOperationalEvent;
use App\Models\TelegramOperationalObservation;
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

it('keeps a named employees concrete action and its apartment traceable in the positive evening block', function () {
    $employeeId = DB::table('users')->insertGetId([
        'name' => 'Анна',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $apartment = Apartment::create(['name' => 'Via X']);
    $message = TelegramOperationalTestDatabase::message(
        'Я заметила дефект белья до заезда и сразу сообщила об этом.',
        messageId: '501',
    );
    $message->telegramUser->update(['linked_user_id' => $employeeId]);
    $message->topic->update(['apartment_id' => $apartment->id]);

    $result = app(TelegramOperationalEventObserver::class)->observe(
        $message->fresh(['chat', 'topic', 'telegramUser', 'attachments'])
    );
    $event = TelegramOperationalEvent::query()->firstOrFail();
    $evidence = $event->evidence()->with('observation.message.telegramUser')->firstOrFail();
    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $positive = collect($preview['sections'])->firstWhere('key', 'positive')['items'][0];
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($result['event_types'])->toBe(['positive_contribution'])
        ->and($event->apartment_id)->toBe($apartment->id)
        ->and($event->confidence)->toBe('high')
        ->and($evidence->observation->message->id)->toBe($message->id)
        ->and($evidence->observation->message->telegramUser->linked_user_id)->toBe($employeeId)
        ->and($evidence->occurred_at)->not->toBeNull()
        ->and($positive['actor_user_id'])->toBe($employeeId)
        ->and($positive['actor_name'])->toBe('Анна')
        ->and($positive['context_label'])->toBe('Via X')
        ->and($positive['evidence'][0]['local_message_id'])->toBe($message->id)
        ->and($positive['evidence'][0]['telegram_message_id'])->toBe('501')
        ->and($text)->toContain('⭐ Хорошая работа:')
        ->toContain('• Via X — Анна заметила дефект белья до заезда и сразу сообщила об этом.')
        ->not->toContain('Осталось на контроле:');
});

it('keeps a concrete contribution valid without apartment or inferred employee identity', function () {
    $reporterId = DB::table('users')->insertGetId([
        'name' => 'Мария',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $message = TelegramOperationalTestDatabase::message(
        'Анна помогла коллеге решить проблему с доступом.',
        messageId: '502',
    );
    $message->telegramUser->update(['linked_user_id' => $reporterId]);
    app(TelegramOperationalEventObserver::class)->observe($message);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $positive = collect($preview['sections'])->firstWhere('key', 'positive')['items'][0];
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($positive['apartment_id'])->toBeNull()
        ->and($positive['actor_user_id'])->toBeNull()
        ->and($positive['context_label'])->toBeNull()
        ->and($text)->toContain('• Анна помогла коллеге решить проблему с доступом.')
        ->not->toContain('Via X');
});

it('does not merge positive evidence into a separate problem or praise routine work', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Не работает ключ от квартиры.', messageId: '601',
    ));
    $positive = $observer->observe(TelegramOperationalTestDatabase::message(
        'Я помогла коллеге решить проблему с ключами.', messageId: '602',
    ));
    $praise = $observer->observe(TelegramOperationalTestDatabase::message(
        'Анна молодец, спасибо!', messageId: '603',
    ));
    $routine = $observer->observe(TelegramOperationalTestDatabase::message(
        'Я убрала квартиру.', messageId: '604',
    ));

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $positiveItems = collect($preview['sections'])->firstWhere('key', 'positive')['items'];
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect(TelegramOperationalEvent::query()->count())->toBe(2)
        ->and($positive['event_types'])->toBe(['positive_contribution'])
        ->and($positiveItems)->toHaveCount(1)
        ->and($praise['event_types'])->toBe([])
        ->and($routine['event_types'])->toBe([])
        ->and($text)->toContain('⭐ Хорошая работа:')
        ->toContain('• Не работает ключ от квартиры.')
        ->not->toContain('Осталось на контроле:')
        ->and(substr_count($text, '⭐ Хорошая работа:'))->toBe(1);
});

it('omits the positive block when no concrete contribution was observed', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Спасибо!', messageId: '701',
    ));
    $observer->observe(TelegramOperationalTestDatabase::message(
        'Не работает замок в квартире.', messageId: '702',
    ));

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($text)->not->toContain('⭐ Хорошая работа:');
});

it('renders the normalized fact with the linked reporter and its supporting quote, but keeps actions unannotated', function () {
    $reporterId = DB::table('users')->insertGetId([
        'name' => 'Анна', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $message = TelegramOperationalTestDatabase::message(
        'В программе гостевого локера ошибка: код неправильный, правильный код 1291.', messageId: '801',
    );
    $message->telegramUser->update(['linked_user_id' => $reporterId]);
    app(TelegramOperationalEventObserver::class)->observe($message);

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);
    $eventItem = collect($preview['events'])->first();
    $attention = substr($text, strpos($text, '🔄 Требует внимания:'));
    $actions = substr($text, strpos($text, 'Осталось сделать:'));

    expect($eventItem['editorial'])
        ->toMatchArray([
            'relevant_today' => true,
            'state' => 'active',
            'needs_attention' => true,
            'next_action' => 'Исправить код гостевого локера в программе.',
        ])
        ->and($text)
        ->toContain('В программе указан неверный код гостевого локера; правильный код — 1291.')
        ->toContain('👤 Анна')
        ->toContain('💬 «В программе гостевого локера ошибка: код неправильный, правильный код 1291.»')
        ->toContain('Осталось сделать:')
        ->and($attention)->toContain('👤 Анна')
        ->and($actions)->toContain('Исправить код гостевого локера в программе.')
        ->not->toContain('👤 Анна')
        ->not->toContain('💬 «');
});

it('quotes linked evidence without guessing an author from an unlinked Telegram profile', function () {
    $message = TelegramOperationalTestDatabase::message(
        'У вытяжки не работает свет.', messageId: '802',
    );
    app(TelegramOperationalEventObserver::class)->observe($message);

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence(
        app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17'),
    );

    expect($text)
        ->toContain('У вытяжки не работает свет.')
        ->toContain('💬 «У вытяжки не работает свет.»')
        ->not->toContain('👤 Worker 101');
});

it('keeps the normalized summary but omits author and quote when the linked source has no usable text', function () {
    $reporterId = DB::table('users')->insertGetId([
        'name' => 'Мария', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $message = TelegramOperationalTestDatabase::message(
        'У вытяжки не работает свет.', messageId: '803',
    );
    $message->telegramUser->update(['linked_user_id' => $reporterId]);
    app(TelegramOperationalEventObserver::class)->observe($message);
    $message->update(['text' => null, 'caption' => null]);

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence(
        app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17'),
    );

    expect($text)
        ->toContain('У вытяжки не работает свет.')
        ->not->toContain('👤 Мария')
        ->not->toContain('💬 «');
});

it('selects the most relevant root evidence instead of a newer unrelated event message', function () {
    $reporterId = DB::table('users')->insertGetId([
        'name' => 'Мария', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $newerAuthorId = DB::table('users')->insertGetId([
        'name' => 'Анна', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $root = TelegramOperationalTestDatabase::message(
        'В программе гостевого локера ошибка: код неправильный, правильный код 1291.', messageId: '804',
    );
    $root->telegramUser->update(['linked_user_id' => $reporterId]);
    app(TelegramOperationalEventObserver::class)->observe($root);
    $event = TelegramOperationalEvent::query()->firstOrFail();
    $newer = TelegramOperationalTestDatabase::message(
        'Спасибо, разобрались.', sentAt: '2026-06-17 08:10:00', messageId: '805',
    );
    $newer->telegramUser->update(['linked_user_id' => $newerAuthorId]);
    $observation = TelegramOperationalObservation::query()->create([
        'telegram_message_id' => $newer->id,
        'source_revision_hash' => hash('sha256', 'newer-unrelated-evidence'),
        'evaluation_kind' => 'message',
        'state' => 'completed',
        'outcome' => 'updated',
        'reason_code' => 'operational_problem',
        'confidence' => 'high',
        'is_current_revision' => true,
        'processed_at' => now(),
    ]);
    $event->evidence()->create([
        'observation_id' => $observation->id,
        'role' => 'report',
        'transition' => 'evidence',
        'status_before' => 'open',
        'status_after' => 'open',
        'confidence' => 'high',
        'occurred_at' => '2026-06-17 08:10:00',
        'is_current_revision' => true,
    ]);

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence(
        app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17'),
    );

    expect($text)
        ->toContain('💬 «В программе гостевого локера ошибка: код неправильный, правильный 1291.»')
        ->toContain('👤 Мария')
        ->not->toContain('💬 «Спасибо, разобрались.»')
        ->not->toContain('👤 Анна');
});

it('identifies the reporter without attributing a reported colleague action to them', function () {
    $reporterId = DB::table('users')->insertGetId([
        'name' => 'Мария', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $message = TelegramOperationalTestDatabase::message(
        'Анна помогла коллеге решить проблему с доступом.', messageId: '806',
    );
    $message->telegramUser->update(['linked_user_id' => $reporterId]);
    app(TelegramOperationalEventObserver::class)->observe($message);

    $text = app(TelegramDigestFormatter::class)->eveningIntelligence(
        app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17'),
    );

    expect($text)
        ->toContain('⭐ Хорошая работа:')
        ->toContain('👤 Мария')
        ->toContain('💬 «Анна помогла коллеге решить проблему с доступом.»')
        ->not->toContain('👤 Анна');
});
