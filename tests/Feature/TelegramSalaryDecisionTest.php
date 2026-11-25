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
    ] as $file) {
        (require database_path('migrations/'.$file))->up();
    }
    Schema::table('salary_questions', function (Blueprint $table): void {
        $table->timestamp('answered_at')->nullable();
        $table->timestamp('answer_seen_at')->nullable();
    });
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
    $this->manager->panelAccesses()->create(['panel' => 'finance', 'can_access' => true]);
    $this->otherManager = User::create([
        'name' => 'Other', 'telegram_id' => '34567', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true,
    ]);
    $this->otherManager->panelAccesses()->create(['panel' => 'finance', 'can_access' => true]);
    $this->question = SalaryQuestion::create([
        'user_id' => $this->employee->id, 'type' => 'Неправильная сумма', 'comment' => 'Не учли две уборки.',
    ])->fresh();
    $this->decisions = app(SalaryQuestionDecisionService::class);
    $this->telegram = app(TelegramSalaryQuestionService::class);
});

afterEach(function (): void {
    DB::purge('sqlite');
});

function salaryMiniAppInitData(User $actor, string $start): string
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

it('creates one salary card with compact decision buttons and a non-empty employee name', function (): void {
    app(StaffFormTelegramService::class)->sendSalaryQuestion($this->question);
    app(StaffFormTelegramService::class)->sendSalaryQuestion($this->question);
    expect((int) $this->question->fresh()->telegram_source_message_id)->toBe(700)
        ->and((int) $this->question->fresh()->telegram_source_thread_id)->toBe(25)
        ->and($this->question->fresh()->status)->toBe('pending');
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
        && str_contains($r['text'], 'Анна Иванова')
        && ! str_contains($r['text'], 'Категория:')
        && str_contains($r['reply_markup']['inline_keyboard'][0][0]['url'], 'startapp=salary_'.$this->question->id.'_approve&mode=compact')
        && $r['message_thread_id'] === 25
        && $r['reply_markup']['inline_keyboard'][0][1]['text'] === '❌ Отказать'
        && $r['reply_markup']['inline_keyboard'][1][0]['text'] === '🔵 В работу');
});

it('requires a comment, assigns a reviewer and rejects stale or unauthorized decisions', function (): void {
    $this->telegram->sendCreated($this->question);
    expect(fn () => $this->decisions->decide($this->question, $this->manager, 'approved', '   ', 0, 'telegram'))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->decisions->decide($this->question, $this->employee, 'approved', 'Не должен иметь доступ', 0, 'telegram'))
        ->toThrow(HttpException::class);
    $first = $this->decisions->decide($this->question, $this->manager, 'in_progress', 'Сверяем табель.', 0, 'telegram');
    expect($this->question->fresh()->status)->toBe('in_progress')
        ->and((int) $this->question->fresh()->reviewed_by)->toBe($this->manager->id)
        ->and($this->question->fresh()->answer_seen_at)->toBeNull();
    expect(fn () => $this->decisions->decide($this->question, $this->otherManager, 'rejected', 'Другое решение', 0, 'telegram'))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->decisions->decide($this->question, $this->otherManager, 'rejected', 'Другое решение', 1, 'telegram'))
        ->toThrow(ValidationException::class);
    $second = $this->decisions->decide($this->question, $this->manager, 'approved', 'Добавим к выплате.', 1, 'telegram');
    $same = $this->decisions->decide($this->question, $this->manager, 'approved', 'Добавим к выплате.', 1, 'telegram');
    expect($same->id)->toBe($second->id)
        ->and(SalaryQuestionDecision::query()->count())->toBe(2)
        ->and($this->question->fresh()->status)->toBe('approved')
        ->and($this->question->fresh()->admin_comment)->toBe('Добавим к выплате.')
        ->and($first->fresh()->card_sync_status)->toBe('superseded');
    Queue::assertPushed(DeliverSalaryQuestionDecision::class);
});

it('delivers a decision once and edits the original card without adding another group message', function (): void {
    $this->telegram->sendCreated($this->question);
    $decision = $this->decisions->decide($this->question, $this->manager, 'rejected', 'Всё уже учтено.', 0, 'telegram');
    $job = new DeliverSalaryQuestionDecision($decision->id);
    $job->handle($this->telegram);
    $job->handle($this->telegram);
    expect($decision->fresh()->delivery_status)->toBe('sent')
        ->and($decision->fresh()->card_sync_status)->toBe('synced');
    $dm = Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '12345');
    expect($dm->count())->toBe(1);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageText')
        && (string) $r['chat_id'] === '-100123456'
        && (int) $r['message_id'] === 700
        && str_contains($r['text'], 'Отказано')
        && str_contains($r['text'], 'Всё уже учтено.')
        && count($r['reply_markup']['inline_keyboard']) === 1);
});

it('does not resend a decision with an ambiguous delivery outcome', function (): void {
    $this->telegram->sendCreated($this->question);
    $decision = $this->decisions->decide($this->question, $this->manager, 'approved', 'Исправим начисления.', 0, 'telegram');
    Http::swap(new \Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(fn ($r) => str_ends_with($r->url(), '/sendMessage')
        ? Http::response(['ok' => false], 503)
        : Http::response(['ok' => true, 'result' => ['message_id' => 700]], 200));
    $job = new DeliverSalaryQuestionDecision($decision->id);
    $job->handle($this->telegram);
    $job->handle($this->telegram);
    expect($decision->fresh()->delivery_status)->toBe('uncertain')
        ->and($this->question->fresh()->status)->toBe('approved')
        ->and(Http::recorded(fn ($r) => str_ends_with($r->url(), '/sendMessage'))->count())->toBe(1);
});

it('authorizes the compact form only for the signed action and refuses a cleaner', function (): void {
    $this->telegram->sendCreated($this->question);
    $start = 'salary_'.$this->question->id.'_approve';
    $this->postJson(route('telegram.auth'), ['init_data' => salaryMiniAppInitData($this->employee, $start)])->assertForbidden();
    $this->postJson(route('telegram.auth'), ['init_data' => salaryMiniAppInitData($this->manager, $start)])
        ->assertOk()->assertJsonPath('redirect', route('salary.decision.show', [$this->question, 'approve']));
    $this->get(route('salary.decision.show', [$this->question, 'approve']))->assertOk()->assertSee('Что будет сделано?');
    $this->get(route('salary.decision.show', [$this->question, 'reject']))->assertForbidden();
    $this->postJson(route('salary.decision.store', [$this->question, 'reject']), ['version' => 0, 'comment' => 'Отказать'])->assertForbidden();
    $this->postJson(route('salary.decision.store', [$this->question, 'approve']), ['version' => 0, 'comment' => '   '])->assertUnprocessable();
    $this->post(route('salary.decision.store', [$this->question, 'approve']), ['version' => 0, 'comment' => 'Будет исправлено.'])->assertOk()->assertSee('Решение сохранено');
    expect($this->question->fresh()->status)->toBe('approved');
});

it('does not open a compact salary form for a supervisor without financial access', function (): void {
    $this->telegram->sendCreated($this->question);
    $this->manager->panelAccesses()->delete();
    $this->postJson(route('telegram.auth'), [
        'init_data' => salaryMiniAppInitData($this->manager, 'salary_'.$this->question->id.'_work'),
    ])->assertForbidden();
    expect($this->question->fresh()->status)->toBe('pending');
});

it('keeps legacy statuses and legacy sending unchanged when the pilot is disabled', function (): void {
    config(['services.staff_forms.salary_telegram_decisions_enabled' => false]);
    app(StaffFormTelegramService::class)->sendSalaryQuestion($this->question);
    expect($this->question->fresh()->telegram_source_message_id)->toBeNull()
        ->and($this->question->fresh()->decision_version)->toBe(0)
        ->and(SalaryQuestionDecision::query()->count())->toBe(0);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Новый вопрос по зарплате'));
});
