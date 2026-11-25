<?php

use App\Jobs\DeliverSalaryQuestionDecision;
use App\Models\SalaryQuestion;
use App\Models\SalaryQuestionDecision;
use App\Models\User;
use App\Services\Forms\SalaryQuestionDecisionService;
use App\Services\Forms\StaffFormTelegramService;
use App\Services\Forms\TelegramSalaryQuestionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'services.telegram.bot_token' => 'test-work-token',
        'services.telegram.bot_username' => 'TrisAcademyBot',
        'services.telegram.rich_messages_enabled' => false,
        'services.staff_forms.chat_id' => '-100123456',
        'services.staff_forms.salary_thread_id' => '25',
        'services.staff_forms.schedule_thread_id' => '26',
        'services.staff_forms.feedback_thread_id' => '27',
        'services.staff_forms.schedule_telegram_decisions_enabled' => true,
        'services.staff_forms.feedback_telegram_decisions_enabled' => true,
        'services.staff_forms.salary_telegram_decisions_enabled' => true,
        'session.driver' => 'array',
        'cache.default' => 'array',
    ]);
    DB::purge('sqlite');
    foreach ([
        '0001_01_01_000000_create_users_table.php',
        '2026_04_17_183858_create_salary_questions_table.php',
        '2026_04_27_055911_create_user_panel_accesses_table.php',
        '2026_10_21_000000_create_telegram_outbound_operations_table.php',
        '2026_11_24_220000_add_salary_question_decisions.php',
        '2026_04_17_183908_create_schedule_questions_table.php',
        '2026_04_17_183919_create_feedback_suggestions_table.php',
        '2026_11_25_100000_add_staff_text_question_decisions.php',
    ] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    foreach (['salary_questions','schedule_questions','feedback_suggestions'] as $tableName) {
    Schema::table($tableName, function (Blueprint $table): void {
        $table->timestamp('answered_at')->nullable();
        $table->timestamp('answer_seen_at')->nullable();
    });
    }
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(fn ($request) => Http::response(['ok' => true, 'result' => ['message_id' => 700]], 200));
    $this->employee = User::create([
        'name' => ' ', 'telegram_first_name' => 'Анна', 'telegram_last_name' => 'Иванова',
        'telegram_id' => '12345', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true,
    ]);
    $this->manager = User::create([
        'name' => 'Supervisor', 'telegram_id' => '23456', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true,
    ]);
    $this->manager->panelAccesses()->create(['panel' => 'education', 'can_access' => true]);
    $this->otherManager = User::create([
        'name' => 'Other', 'telegram_id' => '34567', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true,
    ]);
    $this->otherManager->panelAccesses()->create(['panel' => 'education', 'can_access' => true]);
    $this->question = SalaryQuestion::create([
        'user_id' => $this->employee->id, 'type' => 'Неправильная сумма', 'comment' => 'Не учли две уборки.',
    ])->fresh();
    $this->decisions = app(SalaryQuestionDecisionService::class);
    $this->telegram = app(TelegramSalaryQuestionService::class);
});

afterEach(function (): void {
    DB::purge('sqlite');
});

function staffTextMiniAppInitData(User $actor, string $start): string
{
    $data = [
        'auth_date' => (string) now()->timestamp,
        'start_param' => $start,
        'user' => json_encode(['id' => (int) $actor->telegram_id, 'first_name' => 'Test'], JSON_THROW_ON_ERROR),
    ];
    ksort($data);
    $check = collect($data)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");
    $data['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', config('services.telegram.bot_token'), 'WebAppData', true));

    return http_build_query($data);
}


it('handles schedule decisions via the shared service and preserves a single delivery', function (): void {
    $question = \App\Models\ScheduleQuestion::create(['user_id' => $this->employee->id, 'type' => 'График', 'comment' => 'Перенесите уборку.'])->fresh();
    $service = app(\App\Services\Forms\ScheduleQuestionDecisionService::class);
    app(StaffFormTelegramService::class)->sendScheduleQuestion($question);
    Http::assertSent(fn ($r) => str_contains($r['reply_markup']['inline_keyboard'][0][0]['url'], 'startapp=schedule_'));
    expect(fn () => $service->decide($question, $this->manager, 'approved', ' ', 0, 'telegram'))->toThrow(ValidationException::class);
    $this->postJson(route('telegram.auth'), ['init_data' => staffTextMiniAppInitData($this->manager, 'schedule_'.$question->id.'_approve')])->assertOk();
    $this->get(route('staff-text.decision.show', ['schedule', $question->id, 'approve']))->assertOk()->assertSee('Вопрос по графику');
    $this->post(route('staff-text.decision.store', ['schedule', $question->id, 'approve']), ['version' => 0, 'comment' => 'Перенесём.'])->assertOk();
    $decision = $question->decisions()->firstOrFail();
    $job = new DeliverSalaryQuestionDecision($decision->id, 'schedule');
    $job->handle(app(TelegramSalaryQuestionService::class));
    $job->handle(app(TelegramSalaryQuestionService::class));
    expect($question->fresh()->status)->toBe('approved')->and($decision->fresh()->delivery_status)->toBe('sent');
    expect(Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '12345')->count())->toBe(1);
});

it('uses a single reply action for feedback and rejects approval', function (): void {
    $question = \App\Models\FeedbackSuggestion::create(['user_id' => $this->employee->id, 'type' => 'Предложение', 'comment' => 'Нужны ключи.'])->fresh();
    app(StaffFormTelegramService::class)->sendFeedbackSuggestion($question);
    Http::assertSent(fn ($r) => $r['reply_markup']['inline_keyboard'][0][0]['text'] === '✍️ Ответить' && count($r['reply_markup']['inline_keyboard']) === 2);
    $service = app(\App\Services\Forms\FeedbackSuggestionDecisionService::class);
    expect(fn () => $service->decide($question, $this->manager, 'approved', 'Принято', 0, 'telegram'))->toThrow(ValidationException::class);
    $this->postJson(route('telegram.auth'), ['init_data' => staffTextMiniAppInitData($this->manager, 'feedback_'.$question->id.'_approve')])->assertForbidden();
    $this->postJson(route('telegram.auth'), ['init_data' => staffTextMiniAppInitData($this->manager, 'feedback_'.$question->id.'_reply')])->assertOk();
    $this->post(route('staff-text.decision.store', ['feedback', $question->id, 'reply']), ['version' => 0, 'comment' => 'Спасибо за предложение.'])->assertOk();
    expect($question->fresh()->status)->toBe('reviewed')->and($question->decisions()->count())->toBe(1);
});

it('refuses staff text review by a cleaner or an ungranted supervisor', function (): void {
    $question = \App\Models\ScheduleQuestion::create(['user_id' => $this->employee->id, 'type' => 'График', 'comment' => 'Перенесите уборку.'])->fresh();
    app(StaffFormTelegramService::class)->sendScheduleQuestion($question);
    $start = 'schedule_'.$question->id.'_approve';
    $this->postJson(route('telegram.auth'), ['init_data' => staffTextMiniAppInitData($this->employee, $start)])->assertForbidden();
    $this->manager->panelAccesses()->delete();
    $this->postJson(route('telegram.auth'), ['init_data' => staffTextMiniAppInitData($this->manager, $start)])->assertForbidden();
    expect($question->fresh()->status)->toBe('pending');
});
