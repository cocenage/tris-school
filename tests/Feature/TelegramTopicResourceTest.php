<?php

use App\Filament\Resources\TelegramTopics\Pages\ListTelegramTopics;
use App\Filament\Resources\TelegramTopics\TelegramTopicResource;
use App\Models\Apartment;
use App\Models\TelegramTopic;
use App\Services\Telegram\TelegramTopicPresenter;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Table;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function () {
    config(['database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');

    TelegramOperationalTestDatabase::refresh();
    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('code')->nullable();
        $table->string('address')->nullable();
        $table->timestamps();
    });

    config([
        'services.telegram.digest_districts' => [
            'navigli' => [
                'label' => 'Navigli',
                'chat_id' => '-1001',
                'duty_thread_id' => '99',
            ],
        ],
        'services.telegram.evening_intelligence_central_chat_id' => null,
        'services.telegram.evening_intelligence_central_thread_id' => null,
    ]);
});

afterEach(function () {
    TelegramOperationalTestDatabase::purge();
    DB::purge('sqlite');
});

it('uses a meaningful stored title and otherwise previews recent messages', function () {
    $first = TelegramOperationalTestDatabase::message(
        'Не работает свет у вытяжки.',
        '2026-06-17 08:00:00',
        '1',
    );
    TelegramOperationalTestDatabase::message(
        'Подсветка совсем не включается.',
        '2026-06-17 08:05:00',
        '2',
    );
    $topic = $first->topic;
    $topic->update(['title' => 'Тема #11']);
    $topic->load('recentMessages');

    $presenter = app(TelegramTopicPresenter::class);

    expect($presenter->title($topic))->toBe('Topic #11')
        ->and($presenter->contextPreview($topic))->toContain('Подсветка совсем не включается.')
        ->and($presenter->contextPreview($topic))->toContain('Не работает свет у вытяжки.');

    $topic->update(['title' => 'Via Savona 12']);
    $topic->refresh()->load('recentMessages');

    expect($presenter->title($topic))->toBe('Via Savona 12')
        ->and($presenter->contextPreview($topic))->toBeNull();

});

it('builds an internal Telegram topic link only from supported existing ids', function () {
    $topic = TelegramOperationalTestDatabase::message('Контекст')->topic->load('chat');
    $presenter = app(TelegramTopicPresenter::class);

    expect($presenter->telegramUrl($topic))->toBe('https://t.me/c/1/11');

    $topic->chat->update(['telegram_chat_id' => 'ordinary-chat']);

    expect($presenter->telegramUrl($topic->fresh('chat')))->toBeNull();
});

it('distinguishes duty and service topics without requiring an apartment', function () {
    $duty = TelegramOperationalTestDatabase::message('Дежурный topic', threadId: '99')->topic->load('chat');
    $service = TelegramOperationalTestDatabase::message('Транспорт', messageId: '2', threadId: '98')->topic->load('chat');
    $service->update(['purpose' => 'mobility']);
    $ordinary = TelegramOperationalTestDatabase::message('Квартира', messageId: '3', threadId: '97')->topic->load('chat');
    $presenter = app(TelegramTopicPresenter::class);

    expect($presenter->roleLabel($duty))->toBe('Дежурный')
        ->and($presenter->mappingLabel($duty))->toBe('Квартира не требуется')
        ->and($presenter->roleLabel($service))->toBe('Служебный')
        ->and($presenter->mappingLabel($service))->toBe('Квартира не требуется')
        ->and($presenter->roleLabel($ordinary))->toBe('Квартирный')
        ->and($presenter->mappingLabel($ordinary))->toBe('Квартира не назначена');
});

it('configures chat grouping, explicit apartment filters, searchable mapping, and Telegram action', function () {
    Apartment::create(['name' => 'Via X', 'code' => 'VX-1', 'address' => 'Milan']);
    TelegramOperationalTestDatabase::message('Контекст');

    $table = TelegramTopicResource::table(Table::make(new ListTelegramTopics));
    $columns = $table->getColumns();
    $filters = $table->getFilters();
    $actions = collect($table->getRecordActions())->map->getName()->all();

    expect(array_keys($table->getGroups()))->toBe(['telegram_chat_id'])
        ->and($table->getDefaultGroup()?->getId())->toBe('telegram_chat_id')
        ->and(array_keys($filters))->toContain('telegram_chat_id', 'without_apartment', 'unmapped_apartment_candidates')
        ->and($columns['apartment_id'])->toBeInstanceOf(SelectColumn::class)
        ->and($columns['apartment_id']->areOptionsSearchable())->toBeTrue()
        ->and($columns['apartment_id']->getOptions())->toBe([1 => 'Via X — VX-1 · Milan'])
        ->and($actions)->toContain('open_telegram', 'edit')
        ->and(app(TelegramTopicPresenter::class)->chatOptions())->toBe([
            DB::connection('analytics')->table('telegram_chats')->value('id') => 'Navigli',
        ]);
});

it('filters unmapped apartment candidates while excluding existing service and duty topics', function () {
    $apartment = Apartment::create(['name' => 'Via Candidate']);
    $mapped = TelegramOperationalTestDatabase::message('Mapped topic', messageId: '1', threadId: '97');
    $mapped->topic->update(['apartment_id' => $apartment->id]);
    TelegramOperationalTestDatabase::message('Unmapped apartment topic', messageId: '2', threadId: '96');
    $duty = TelegramOperationalTestDatabase::message('Duty', messageId: '3', threadId: '99')->topic;
    $service = TelegramOperationalTestDatabase::message('Mobility service', messageId: '4', threadId: '95')->topic;
    $service->update(['purpose' => 'mobility']);

    $candidateThreads = app(TelegramTopicPresenter::class)
        ->scopeUnmappedApartmentCandidates(TelegramTopic::query())
        ->orderBy('telegram_thread_id')
        ->pluck('telegram_thread_id')
        ->all();

    expect($candidateThreads)->toBe(['96'])
        ->and($duty->fresh()->apartment_id)->toBeNull()
        ->and($service->fresh()->apartment_id)->toBeNull();
});

it('does not assign an apartment when a topic title happens to match an apartment name', function () {
    $apartment = Apartment::create(['name' => 'Via Savona 12']);
    $topic = TelegramOperationalTestDatabase::message('Operational message', messageId: '5')->topic;
    $topic->update(['title' => 'Via Savona 12']);

    expect($topic->fresh()->apartment_id)->toBeNull()
        ->and(app(TelegramTopicPresenter::class)->apartmentOptions())
        ->toBe([$apartment->id => 'Via Savona 12']);
});
