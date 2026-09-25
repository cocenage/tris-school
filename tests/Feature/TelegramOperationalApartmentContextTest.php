<?php

use App\Filament\Resources\TelegramTopics\Pages\ListTelegramTopics;
use App\Filament\Resources\TelegramTopics\TelegramTopicResource;
use App\Models\Apartment;
use App\Models\TelegramOperationalEvent;
use App\Services\Telegram\TelegramDigestFormatter;
use App\Services\Telegram\TelegramEveningIntelligenceBuilder;
use App\Services\Telegram\TelegramOperationalEventObserver;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema as FilamentSchema;
use Filament\Tables\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');

    if (DB::connection('sqlite')->getDatabaseName() !== ':memory:') {
        throw new LogicException('Apartment context tests require an in-memory primary database.');
    }

    TelegramOperationalTestDatabase::refresh();
    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('code')->nullable();
        $table->string('address')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    TelegramOperationalTestDatabase::purge();
    DB::purge('sqlite');
});

it('carries mapped apartment topics into events and both evening handoff sections without crossing chats', function () {
    $viaX = Apartment::create(['name' => 'Via X']);
    $viaY = Apartment::create(['name' => 'Via Y']);
    $viaZ = Apartment::create(['name' => 'Via Z']);
    $observer = app(TelegramOperationalEventObserver::class);

    $sources = [
        ['Дверь закрыта, никто не открывает.', '1', '-1001', '11', $viaX],
        ['У вытяжки не работает свет.', '2', '-1001', '12', $viaY],
        ['Не работает замок в квартире.', '3', '-1002', '11', $viaZ],
    ];

    foreach ($sources as [$text, $messageId, $chatId, $threadId, $apartment]) {
        $message = TelegramOperationalTestDatabase::message($text, messageId: $messageId, chatId: $chatId, threadId: $threadId);
        $message->topic->update(['apartment_id' => $apartment->id]);
        $observer->observe($message->fresh(['chat', 'topic', 'telegramUser', 'attachments']));
    }

    $unmapped = TelegramOperationalTestDatabase::message('Не работает кран в ванной.', messageId: '4', chatId: '-1001', threadId: '13');
    $unmapped->topic->update(['title' => 'Via Unknown']);
    $observer->observe($unmapped);

    $navigli = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17', [
        'district' => ['key' => 'navigli', 'label' => 'Navigli', 'chat_id' => '-1001'],
    ]);
    $lodi = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17', [
        'district' => ['key' => 'lodi', 'label' => 'Lodi', 'chat_id' => '-1002'],
    ]);
    $navigliItems = collect($navigli['sections'])->flatMap(fn (array $section) => $section['items'])->keyBy('summary');
    $navigliText = app(TelegramDigestFormatter::class)->eveningIntelligence($navigli);
    $lodiText = app(TelegramDigestFormatter::class)->eveningIntelligence($lodi);

    expect(TelegramOperationalEvent::query()->whereNotNull('apartment_id')->count())->toBe(3)
        ->and($navigliItems->get('У вытяжки не работает свет.')['apartment_id'])->toBe($viaY->id)
        ->and($navigliItems->get('Не работает кран в ванной.')['apartment_id'])->toBeNull()
        ->and($navigliText)->toContain('• Via Y — У вытяжки не работает свет.')
        ->toContain('• Via X — Проверить доступ в квартиру.')
        ->toContain('• Via Unknown — Не работает кран в ванной.')
        ->not->toContain('Via Z')
        ->and($lodiText)->toContain('Via Z — Не работает замок в квартире.')
        ->not->toContain('Via X')
        ->not->toContain('Via Y');
});

it('prefers a mapped apartment over topic title and hides duty topic titles', function () {
    config(['services.telegram.digest_districts.navigli' => [
        'label' => 'Navigli',
        'chat_id' => '-1001',
        'duty_thread_id' => '99',
        'latitude' => 45.4514,
        'longitude' => 9.1749,
    ]]);
    $apartment = Apartment::create(['name' => 'Via Mapped']);
    $mapped = TelegramOperationalTestDatabase::message('Не работает свет.', messageId: '21', threadId: '21');
    $mapped->topic->update(['title' => 'Via Topic', 'apartment_id' => $apartment->id]);
    $duty = TelegramOperationalTestDatabase::message('Не работает свет.', messageId: '22', threadId: '99');
    $duty->topic->update(['title' => 'Дежурный район']);
    $observer = app(TelegramOperationalEventObserver::class);
    $observer->observe($mapped->fresh(['chat', 'topic', 'telegramUser', 'attachments']));
    $observer->observe($duty->fresh(['chat', 'topic', 'telegramUser', 'attachments']));

    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $items = collect($preview['events'])->keyBy('event_key')->values();
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($items->pluck('context_label'))->toContain('Via Mapped', null)
        ->and($text)->toContain('Via Mapped — Не работает свет.')
        ->not->toContain('Via Topic')
        ->not->toContain('Дежурный район');
});

it('backfills an existing event when its topic receives an explicit apartment mapping', function () {
    $apartment = Apartment::create(['name' => 'Via X']);
    $message = TelegramOperationalTestDatabase::message('Не работает кран в ванной.');
    app(TelegramOperationalEventObserver::class)->observe($message);
    $event = TelegramOperationalEvent::query()->firstOrFail();

    expect($event->apartment_id)->toBeNull();

    $message->topic->update(['apartment_id' => $apartment->id]);

    expect($event->fresh()->apartment_id)->toBe($apartment->id)
        ->and($event->fresh()->apartment->name)->toBe('Via X');
});

it('maps apartments per topic in one district while leaving its duty topic unmapped', function () {
    $viaX = Apartment::create(['name' => 'Via X']);
    $viaY = Apartment::create(['name' => 'Via Y']);
    $viaZ = Apartment::create(['name' => 'Via Z']);
    $observer = app(TelegramOperationalEventObserver::class);
    $first = TelegramOperationalTestDatabase::message('Не работает замок.', messageId: '51', threadId: '11');
    $second = TelegramOperationalTestDatabase::message('Не работает кран.', messageId: '52', threadId: '12');
    $duty = TelegramOperationalTestDatabase::message('Дежурный topic.', messageId: '53', threadId: '99');
    $first->topic->update(['apartment_id' => $viaX->id]);
    $second->topic->update(['apartment_id' => $viaY->id]);
    $observer->observe($first->fresh(['chat', 'topic', 'telegramUser', 'attachments']));
    $observer->observe($second->fresh(['chat', 'topic', 'telegramUser', 'attachments']));

    $first->topic->update(['apartment_id' => $viaZ->id]);
    $events = TelegramOperationalEvent::query()->orderBy('id')->get();

    expect($events[0]->apartment_id)->toBe($viaZ->id)
        ->and($events[1]->apartment_id)->toBe($viaY->id)
        ->and($duty->topic->fresh()->apartment_id)->toBeNull()
        ->and($first->topic->fresh()->apartment_id)->toBe($viaZ->id)
        ->and($second->topic->fresh()->apartment_id)->toBe($viaY->id);
});

it('offers only existing apartments in the searchable nullable topic editor', function () {
    $viaX = Apartment::create(['name' => 'Via X', 'address' => 'Via Roma 1']);
    $viaY = Apartment::create(['name' => 'Via Y', 'code' => 'VY-2']);
    $schema = TelegramTopicResource::form(FilamentSchema::make());
    $select = collect($schema->getComponents())->first(fn ($component): bool => $component->getName() === 'apartment_id');

    expect($select)->toBeInstanceOf(Select::class)
        ->and($select->getOptions())->toBe([
            $viaX->id => 'Via X — Via Roma 1',
            $viaY->id => 'Via Y — VY-2',
        ])
        ->and($select->isSearchable())->toBeTrue();
});

it('shows the source chat, topic, thread, apartment and mapping status in the topic table', function () {
    $table = TelegramTopicResource::table(Table::make(new ListTelegramTopics));
    $columns = $table->getColumns();

    expect(array_keys($columns))->toContain(
        'chat.title',
        'title',
        'context_preview',
        'telegram_thread_id',
        'apartment_id',
        'topic_role',
        'mapping_status',
    );
});

it('resolves a replied access problem with evidence and shows it as resolved instead of pending', function () {
    $apartment = Apartment::create(['name' => 'Via X']);
    $root = TelegramOperationalTestDatabase::message('Не открывается дверь в квартире.', '2026-06-17 08:00:00', '101');
    $root->topic->update(['apartment_id' => $apartment->id]);
    $observer = app(TelegramOperationalEventObserver::class);
    $created = $observer->observe($root->fresh(['chat', 'topic', 'telegramUser', 'attachments']));

    expect($created['outcome'])->toBe('created')
        ->and(TelegramOperationalEvent::query()->sole()->status)->toBe('open');

    $answer = TelegramOperationalTestDatabase::message(
        'Всё, открыли, проблема решена.', '2026-06-17 10:00:00', '102',
        ['message' => ['reply_to_message' => ['message_id' => 101]]],
    );
    $result = $observer->observe($answer);
    $event = TelegramOperationalEvent::query()->with('evidence.observation.message')->sole();
    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17', [
        'district' => ['key' => 'lambrate', 'label' => 'Lambrate', 'chat_id' => '-1001'],
    ]);
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($result['outcome'])->toBe('resolved')
        ->and($event->status)->toBe('resolved')
        ->and($event->apartment_id)->toBe($apartment->id)
        ->and($event->resolved_at)->not->toBeNull()
        ->and($event->evidence->pluck('transition')->all())->toBe(['created', 'resolved'])
        ->and($event->evidence->last()->observation->message->id)->toBe($answer->id)
        ->and($preview['sections'][0]['key'])->toBe('resolved')
        ->and($preview['sections'][0]['items'][0]['evidence'][1]['transition'])->toBe('resolved')
        ->and($text)->toContain('✅ Решено сегодня:')
        ->toContain('• Via X — проблема с доступом решена.')
        ->not->toContain('Осталось на контроле:');
});

it('does not close another topic with a bare resolution, but accepts a direct reply in the same topic', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message('Не работает замок в квартире.', messageId: '201', threadId: '11');
    $observer->observe($root);
    $bare = TelegramOperationalTestDatabase::message('Готово.', messageId: '202', threadId: '11');
    $crossTopic = TelegramOperationalTestDatabase::message(
        'Готово.', messageId: '203', threadId: '12',
        raw: ['message' => ['reply_to_message' => ['message_id' => 201]]],
    );

    expect($observer->observe($bare)['outcome'])->toBe('no_event')
        ->and($observer->observe($crossTopic)['outcome'])->toBe('no_event')
        ->and(TelegramOperationalEvent::query()->count())->toBe(1)
        ->and(TelegramOperationalEvent::query()->sole()->status)->toBe('open');

    $directReply = TelegramOperationalTestDatabase::message(
        'Готово.', messageId: '204', threadId: '11',
        raw: ['message' => ['reply_to_message' => ['message_id' => 201]]],
    );

    expect($observer->observe($directReply)['outcome'])->toBe('resolved')
        ->and(TelegramOperationalEvent::query()->sole()->status)->toBe('resolved');
});

it('reopens the same event on confirmed recurrence and no longer presents it as resolved', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message('Не открывается дверь в квартире.', messageId: '301');
    $observer->observe($root);
    $answer = TelegramOperationalTestDatabase::message(
        'Дверь открыли.', messageId: '302',
        raw: ['message' => ['reply_to_message' => ['message_id' => 301]]],
    );
    $observer->observe($answer);
    $recurrence = TelegramOperationalTestDatabase::message(
        'Дверь снова не открывается.', messageId: '303',
        raw: ['message' => ['reply_to_message' => ['message_id' => 301]]],
    );
    $result = $observer->observe($recurrence);
    $preview = app(TelegramEveningIntelligenceBuilder::class)->build('2026-06-17');
    $text = app(TelegramDigestFormatter::class)->eveningIntelligence($preview);

    expect($result['outcome'])->toBe('reopened')
        ->and(TelegramOperationalEvent::query()->sole()->status)->toBe('reopened')
        ->and(TelegramOperationalEvent::query()->sole()->evidence()->pluck('transition')->all())->toBe(['created', 'resolved', 'reopened'])
        ->and($text)->toContain('Осталось сделать:')
        ->not->toContain('✅ Решено сегодня:');
});

it('recognizes a concrete linked resolution without losing its evidence', function (string $problem, string $answer) {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message($problem, messageId: '401');
    $observer->observe($root);
    $reply = TelegramOperationalTestDatabase::message(
        $answer, messageId: '402',
        raw: ['message' => ['reply_to_message' => ['message_id' => 401]]],
    );

    expect($observer->observe($reply)['outcome'])->toBe('resolved')
        ->and(TelegramOperationalEvent::query()->count())->toBe(1)
        ->and(TelegramOperationalEvent::query()->sole()->status)->toBe('resolved')
        ->and(TelegramOperationalEvent::query()->sole()->evidence()->orderBy('id')->pluck('transition')->all())
        ->toBe(['created', 'resolved']);
})->with([
    'keys found' => ['Нет ключей в квартире.', 'Ключи нашли.'],
    'towel replaced' => ['Брак полотенца.', 'Полотенце заменили.'],
]);

it('does not reopen a resolved problem for a later action without confirmed recurrence', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message('Не работает замок в квартире.', '2026-06-17 08:00:00', '501');
    $observer->observe($root);
    $done = TelegramOperationalTestDatabase::message(
        'Готово.', '2026-06-17 09:00:00', '502',
        ['message' => ['reply_to_message' => ['message_id' => 501]]],
    );
    $observer->observe($done);
    $resolvedAt = TelegramOperationalEvent::query()->sole()->resolved_at;
    $check = TelegramOperationalTestDatabase::message(
        'Я проверю замок ещё раз.', '2026-06-17 10:00:00', '503',
        ['message' => ['reply_to_message' => ['message_id' => 501]]],
    );
    $result = $observer->observe($check);

    expect($result['outcome'])->toBe('updated')
        ->and(TelegramOperationalEvent::query()->sole()->status)->toBe('resolved')
        ->and(TelegramOperationalEvent::query()->sole()->resolved_at?->toIso8601String())
        ->toBe($resolvedAt?->toIso8601String());
});

it('does not reopen a resolved problem from an uncertain recurrence report', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message('Не открывается дверь в квартире.', messageId: '551');
    $observer->observe($root);
    $done = TelegramOperationalTestDatabase::message(
        'Дверь открыли.', messageId: '552',
        raw: ['message' => ['reply_to_message' => ['message_id' => 551]]],
    );
    $observer->observe($done);
    $uncertain = TelegramOperationalTestDatabase::message(
        'Кажется, дверь снова не открывается.', messageId: '553',
        raw: ['message' => ['reply_to_message' => ['message_id' => 551]]],
    );

    expect($observer->observe($uncertain)['outcome'])->not->toBe('reopened')
        ->and(TelegramOperationalEvent::query()->sole()->status)->toBe('resolved');
});

it('labels a resolution as today only on its evidence date, not on later activity', function () {
    $observer = app(TelegramOperationalEventObserver::class);
    $root = TelegramOperationalTestDatabase::message('Не открывается дверь в квартире.', '2026-06-16 08:00:00', '601');
    $observer->observe($root);
    $done = TelegramOperationalTestDatabase::message(
        'Дверь открыли.', '2026-06-17 09:00:00', '602',
        ['message' => ['reply_to_message' => ['message_id' => 601]]],
    );
    $observer->observe($done);
    $builder = app(TelegramEveningIntelligenceBuilder::class);
    $formatter = app(TelegramDigestFormatter::class);
    $resolutionDay = $formatter->eveningIntelligence($builder->build('2026-06-17'));

    $check = TelegramOperationalTestDatabase::message(
        'Я проверю замок ещё раз.', '2026-06-18 10:00:00', '603',
        ['message' => ['reply_to_message' => ['message_id' => 601]]],
    );
    $observer->observe($check);
    $laterDay = $formatter->eveningIntelligence($builder->build('2026-06-18'));

    expect($resolutionDay)->toContain('✅ Решено сегодня:')
        ->not->toContain('• Не открывается дверь в квартире.')
        ->and($laterDay)->not->toContain('✅ Решено сегодня:')
        ->not->toContain('• Не открывается дверь в квартире.');
});
