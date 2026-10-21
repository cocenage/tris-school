<?php

use App\Models\SalaryQuestion;
use App\Models\TelegramOutboundOperation;
use App\Models\User;
use App\Services\Forms\StaffFormTelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
        'services.telegram.bot_token' => 'work-test-token',
        'services.staff_forms.chat_id' => 'staff-chat', 'services.staff_forms.salary_thread_id' => '11',
    ]);
    DB::purge('sqlite');
    (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
    (require database_path('migrations/2026_04_17_183858_create_salary_questions_table.php'))->up();
    (require database_path('migrations/2026_10_21_000000_create_telegram_outbound_operations_table.php'))->up();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 44]])]);
});

afterEach(function (): void {
    DB::purge('sqlite');
});

it('reserves and links a staff form notification before Telegram transport', function (): void {
    $user = User::create(['name' => 'Employee', 'telegram_id' => 'employee-chat']);
    $request = SalaryQuestion::create([
        'user_id' => $user->id, 'type' => 'salary', 'comment' => 'private comment', 'attachments' => [],
    ]);

    app(StaffFormTelegramService::class)->sendSalaryQuestion($request);

    $operation = TelegramOutboundOperation::query()->sole();
    expect($operation->status)->toBe('sent')
        ->and($operation->notification_type)->toBe('staff_form')
        ->and($operation->source_type)->toBe('SalaryQuestion')
        ->and($operation->source_id)->toBe((string) $request->id)
        ->and($operation->chat_id)->toBe('staff-chat')
        ->and($operation->message_thread_id)->toBe('11')
        ->and(json_encode($operation->getAttributes()))->not->toContain('private comment', 'work-test-token');
});
