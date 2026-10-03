<?php

use App\Jobs\ProcessTelegramInstructionAutoReply;
use App\Jobs\ProcessTelegramOperationalMessage;
use App\Models\Instruction;
use App\Models\TelegramMessage;
use App\Services\Telegram\Knowledge\InstructionTelegramSearchService;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramInstructionAutoReplyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramOperationalTestDatabase;

beforeEach(function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    TelegramOperationalTestDatabase::refresh();
    foreach ([
        '2026_09_27_000000_create_telegram_scheduled_messages_tables.php',
        '2026_10_01_020000_create_scheduled_control_response_tables.php',
        '2026_10_03_000000_allow_replyless_scheduled_control_responses.php',
    ] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    Cache::flush();
    config(['services.telegram.main_bot_auto_replies_enabled' => false]);
    Http::fake();
});

afterEach(function (): void {
    TelegramOperationalTestDatabase::purge();
    DB::purge('sqlite');
});

it('gates keyword replies at execution while preserving enabled behavior', function (bool $enabled): void {
    config(['services.telegram.main_bot_auto_replies_enabled' => $enabled]);
    $message = TelegramOperationalTestDatabase::message('/help полотенце');
    $instruction = new Instruction(['title' => 'Test instruction', 'slug' => 'test-instruction']);
    $instruction->id = 123;
    $search = Mockery::mock(InstructionTelegramSearchService::class);
    $search->shouldReceive('findForText')->times($enabled ? 1 : 0)->with($message->text)->andReturn($instruction);
    $bot = Mockery::mock(TelegramBotService::class);
    $bot->shouldReceive('sendMessage')->times($enabled ? 1 : 0)->andReturn(900);

    (new ProcessTelegramInstructionAutoReply($message->id))->handle(new TelegramInstructionAutoReplyService($search, $bot));

    expect(Cache::has('telegram_instruction_reply:'.$message->telegram_chat_id.':'.$message->telegram_topic_id.':123'))->toBe($enabled);
})->with([false, true]);

it('persists incoming keyword messages and dispatches observation with replies disabled', function (bool $workAllowed): void {
    config([
        'services.telegram.work_webhook_secret' => 'test-secret',
        'services.telegram.operational_observer_enabled' => true,
        'services.telegram.assistant_enabled' => true,
        'services.telegram.work_allowed_chat_ids' => $workAllowed ? ['-1001'] : [],
    ]);
    Queue::fake();
    $this->mock(\App\Services\Telegram\TelegramAssistantService::class, function ($mock) use ($workAllowed): void {
        $mock->shouldReceive('isActivated')->times($workAllowed ? 1 : 0)->andReturnTrue();
        $mock->shouldReceive('handle')->times($workAllowed ? 1 : 0);
    });
    $this->postJson('/telegram/work-webhook/test-secret', [
        'message' => [
            'message_id' => 42,
            'date' => now()->timestamp,
            'chat' => ['id' => -1001, 'type' => 'supergroup'],
            'from' => ['id' => 101, 'is_bot' => false],
            'text' => '/help полотенце',
        ],
    ])->assertOk();

    $message = TelegramMessage::query()->sole();
    expect($message->text)->toBe('/help полотенце');
    Queue::assertPushed(ProcessTelegramOperationalMessage::class, fn ($job): bool => $job->telegramMessageId === $message->id);
    Queue::assertPushed(ProcessTelegramOperationalMessage::class, 1);
    if ($workAllowed) {
        Queue::assertPushed(ProcessTelegramInstructionAutoReply::class, fn ($job): bool => $job->telegramMessageId === $message->id);
    } else {
        Queue::assertNotPushed(ProcessTelegramInstructionAutoReply::class);
    }
    Http::assertNothingSent();
})->with(['work source' => [true], 'OI-only source' => [false]]);
