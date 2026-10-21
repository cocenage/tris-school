<?php

use App\Filament\Widgets\TelegramDeliveryCenterWidget;
use App\Models\TelegramChat;
use App\Models\TelegramOutboundOperation;
use App\Models\TelegramScheduledMessage;
use App\Models\TelegramScheduledMessageDelivery;
use App\Models\User;
use App\Services\Telegram\TelegramDeliveryCenter;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function (): void {
    Schema::dropIfExists('telegram_outbound_operations');
    (require database_path('migrations/2026_10_21_000000_create_telegram_outbound_operations_table.php'))->up();
    config([
        'services.telegram.digest_districts' => collect(['navigli', 'lodi', 'lambrate', 'como', 'certosa'])
            ->mapWithKeys(fn ($key) => [$key => [
                'label' => ucfirst($key), 'chat_id' => 'chat-'.$key, 'duty_thread_id' => '2',
                'latitude' => 45.4, 'longitude' => 9.2,
            ]])->all(),
        'services.telegram.evening_intelligence_delivery_enabled' => true,
        'services.telegram.evening_intelligence_delivery_mode' => 'centralized',
        'services.telegram.evening_intelligence_central_chat_id' => 'central-chat',
        'services.telegram.evening_intelligence_central_thread_id' => '2',
    ]);
});

afterEach(function (): void {
    Schema::dropIfExists('telegram_outbound_operations');
});

it('shows all five district morning and evening slots while distinguishing missed and accepted', function (): void {
    TelegramOutboundOperation::query()->create([
        'operation_key' => 'morning_digest:2026-10-22:navigli',
        'notification_type' => 'morning_digest', 'method' => 'sendMessage', 'bot' => 'analytics',
        'chat_id' => 'chat-navigli', 'district' => 'navigli', 'status' => 'sent',
        'attempts' => 1, 'created_at' => '2026-10-21 08:00:00',
    ]);

    $snapshot = app(TelegramDeliveryCenter::class)->snapshot(CarbonImmutable::parse('2026-10-22 21:00', 'Europe/Rome'));

    expect($snapshot['slots'])->toHaveCount(10)
        ->and($snapshot['counts']['expected'])->toBe(10)
        ->and($snapshot['counts']['sent'])->toBe(1)
        ->and($snapshot['counts']['missed'])->toBe(9);
});

it('does not claim a pre-monitoring gap was a missed delivery', function (): void {
    $snapshot = app(TelegramDeliveryCenter::class)->snapshot(CarbonImmutable::parse('2026-10-22 21:00', 'Europe/Rome'));

    expect($snapshot['counts']['missed'])->toBe(0)
        ->and($snapshot['counts']['unmonitored'])->toBe(10);
});

it('treats an abandoned API attempt as an unknown result', function (): void {
    TelegramOutboundOperation::query()->create([
        'notification_type' => 'task', 'method' => 'sendMessage', 'bot' => 'work',
        'chat_id' => 'test-chat', 'status' => 'pending', 'attempts' => 1,
        'attempted_at' => '2026-10-22 08:00:00', 'created_at' => '2026-10-22 08:00:00',
    ]);

    $snapshot = app(TelegramDeliveryCenter::class)->snapshot(CarbonImmutable::parse('2026-10-22 21:00', 'Europe/Rome'));

    expect($snapshot['counts']['uncertain'])->toBe(1)
        ->and($snapshot['counts']['pending'])->toBe(0);
});

it('shows an event reserved before the API as missed when no attempt follows', function (): void {
    TelegramOutboundOperation::query()->create([
        'operation_key' => 'day_off:created:42', 'notification_type' => 'day_off',
        'method' => 'sendMessage', 'bot' => 'work', 'chat_id' => 'test-chat',
        'status' => 'expected', 'attempts' => 0, 'created_at' => '2026-10-22 08:00:00',
    ]);

    $snapshot = app(TelegramDeliveryCenter::class)->snapshot(CarbonImmutable::parse('2026-10-22 21:00', 'Europe/Rome'));

    expect($snapshot['counts']['expected'])->toBe(11)
        ->and($snapshot['counts']['missed'])->toBe(11)
        ->and($snapshot['counts']['pending'])->toBe(0);
});

it('keeps five district slots across both Europe Rome daylight-saving transitions', function (): void {
    $center = app(TelegramDeliveryCenter::class);
    $spring = $center->snapshot(CarbonImmutable::parse('2026-03-29 21:00', 'Europe/Rome'));
    $autumn = $center->snapshot(CarbonImmutable::parse('2026-10-25 21:00', 'Europe/Rome'));

    expect($spring['slots'])->toHaveCount(10)
        ->and($autumn['slots'])->toHaveCount(10)
        ->and($spring['slots'][0]['due']->utcOffset())->toBe(120)
        ->and($autumn['slots'][0]['due']->utcOffset())->toBe(60);
});

it('limits the delivery center to administrators', function (): void {
    auth()->setUser((new User)->forceFill(['role' => 'supervisor']));
    expect(TelegramDeliveryCenterWidget::canView())->toBeFalse();

    auth()->setUser((new User)->forceFill(['role' => 'admin']));
    expect(TelegramDeliveryCenterWidget::canView())->toBeTrue();
    auth()->logout();
});

it('renders the delivery center on the admin panel without calling Telegram', function (): void {
    auth()->setUser((new User)->forceFill(['role' => 'admin']));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(TelegramDeliveryCenterWidget::class)
        ->assertSee('Telegram · Центр доставки')
        ->assertSee('Ожидалось')
        ->assertSee('нет достоверного учёта');

    auth()->logout();
});

it('uses existing scheduled delivery records and excludes disabled, off-day and conditional controls', function (): void {
    Schema::connection('analytics')->create('telegram_chats', function ($table): void {
        $table->id();
        $table->string('telegram_chat_id');
        $table->string('title')->nullable();
        $table->boolean('is_enabled')->default(true);
        $table->timestamps();
    });
    (require database_path('migrations/2026_09_27_000000_create_telegram_scheduled_messages_tables.php'))->up();

    try {
        $chat = TelegramChat::query()->create(['telegram_chat_id' => 'test-chat', 'is_enabled' => true]);
        $create = fn (string $type, bool $enabled, array $days) => TelegramScheduledMessage::query()->create([
            'name' => $type, 'control_type' => $type, 'telegram_chat_record_id' => $chat->id,
            'message' => 'text', 'send_time' => '09:00:00', 'weekdays' => $days, 'enabled' => $enabled,
        ]);
        $due = $create('schedule_checked', true, [4]);
        $create('first_cleanings_started', false, [4]);
        $create('couriers_completed', true, [5]);
        $conditional = $create('extra_payments_completed', true, [4]);

        $at = CarbonImmutable::parse('2026-10-22 22:00', 'Europe/Rome');
        $before = app(TelegramDeliveryCenter::class)->snapshot($at);
        expect(collect($before['slots'])->where('type', 'scheduled_control'))->toHaveCount(1)
            ->and($before['counts']['missed'])->toBe(1);

        $delivery = TelegramScheduledMessageDelivery::query()->create([
            'scheduled_message_id' => $due->id, 'control_type' => $due->control_type,
            'scheduled_for' => '2026-10-22 09:00:00', 'status' => 'sent',
        ]);
        TelegramOutboundOperation::query()->create([
            'operation_key' => 'scheduled_delivery:'.$delivery->id,
            'notification_type' => 'scheduled_control', 'method' => 'sendMessage', 'bot' => 'work',
            'chat_id' => 'test-chat', 'status' => 'sent', 'attempts' => 1,
            'created_at' => '2026-10-22 09:00:00',
        ]);
        $after = app(TelegramDeliveryCenter::class)->snapshot($at);
        expect(collect($after['slots'])->where('type', 'scheduled_control')->first()['status'])->toBe('sent')
            ->and($after['counts']['expected'])->toBe(11)
            ->and($after['counts']['sent'])->toBe(1);

        TelegramScheduledMessageDelivery::query()->create([
            'scheduled_message_id' => $conditional->id, 'control_type' => $conditional->control_type,
            'scheduled_for' => '2026-10-22 09:05:00', 'status' => 'pending',
        ]);
        $triggered = app(TelegramDeliveryCenter::class)->snapshot($at);
        expect(collect($triggered['slots'])->where('type', 'scheduled_control'))->toHaveCount(2);
    } finally {
        Schema::dropIfExists('telegram_scheduled_message_deliveries');
        Schema::dropIfExists('telegram_scheduled_messages');
        Schema::connection('analytics')->dropIfExists('telegram_chats');
    }
});
