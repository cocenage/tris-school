<?php

use App\Jobs\SendControlCompletionNotification;
use App\Models\Control;
use App\Models\ControlCompletionNotification;
use App\Models\ControlResponse;
use App\Models\ControlResponseDraft;
use App\Models\User;
use App\Services\Controls\ControlCompletionNotificationService;
use App\Services\Controls\ControlRunManager;
use App\Services\Controls\ControlSchema;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramOutboundMonitor;
use App\Services\TelegramMiniAppService;
use App\Services\UserApplicationBadgeService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    foreach (['control_completion_notifications', 'activity_log', 'control_responses', 'control_response_drafts', 'apartments', 'controls', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('role')->default('cleaner');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
        $table->string('telegram_avatar_path')->nullable();
        $table->string('telegram_username')->nullable();
        $table->string('telegram_first_name')->nullable();
        $table->string('telegram_last_name')->nullable();
        $table->string('telegram_photo_url')->nullable();
        $table->timestamp('telegram_last_auth_at')->nullable();
        $table->string('telegram_login_source')->nullable();
        $table->timestamp('last_login_at')->nullable();
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->string('remember_token')->nullable();
        $table->string('telegram_id')->nullable();
        $table->timestamps();
    });

    Schema::create('controls', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('name');
        $table->string('slug')->unique();
        $table->json('main')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('image')->nullable();
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamps();
    });

    Schema::create('control_response_drafts', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('control_id');
        $table->unsignedBigInteger('supervisor_id')->nullable();
        $table->unsignedBigInteger('cleaner_id')->nullable();
        $table->unsignedBigInteger('apartment_id')->nullable();
        $table->boolean('is_assigned')->default(false);
        $table->string('previous_cleaner')->nullable();
        $table->date('cleaning_date')->nullable();
        $table->date('inspection_date')->nullable();
        $table->text('comment')->nullable();
        $table->json('responses')->nullable();
        $table->json('schema_snapshot')->nullable();
        $table->string('schema_version', 64)->nullable();
        $table->uuid('run_id')->nullable()->unique();
        $table->unsignedInteger('revision')->default(0);
        $table->string('state', 20)->default('active');
        $table->timestamps();
    });

    Schema::create('control_responses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('control_id');
        $table->unsignedBigInteger('supervisor_id')->nullable();
        $table->unsignedBigInteger('cleaner_id')->nullable();
        $table->unsignedBigInteger('apartment_id')->nullable();
        $table->boolean('is_assigned')->default(false);
        $table->string('previous_cleaner')->nullable();
        $table->date('cleaning_date')->nullable();
        $table->date('inspection_date')->nullable();
        $table->text('comment')->nullable();
        $table->json('responses')->nullable();
        $table->json('schema_snapshot')->nullable();
        $table->string('schema_version', 64)->nullable();
        $table->uuid('submission_token')->nullable()->unique();
        $table->unsignedInteger('total_points')->default(0);
        $table->unsignedInteger('max_points')->default(0);
        $table->unsignedTinyInteger('score_percent')->default(0);
        $table->unsignedInteger('penalty_points')->default(0);
        $table->unsignedInteger('errors_count')->default(0);
        $table->boolean('has_critical_failure')->default(false);
        $table->string('result_zone')->nullable();
        $table->text('result_zone_reason')->nullable();
        $table->string('status')->default('sent');
        $table->timestamp('sent_at')->nullable();
        $table->timestamps();
    });

    Schema::create('activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('log_name')->nullable();
        $table->text('description');
        $table->string('subject_type')->nullable();
        $table->unsignedBigInteger('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->unsignedBigInteger('causer_id')->nullable();
        $table->json('properties')->nullable();
        $table->string('event')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });

    Schema::create('control_completion_notifications', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('control_response_id')->unique();
        $table->unsignedBigInteger('recipient_user_id')->nullable();
        $table->string('status')->default('pending');
        $table->unsignedInteger('attempts')->default(0);
        $table->unsignedBigInteger('telegram_message_id')->nullable();
        $table->string('last_error', 100)->nullable();
        $table->timestamp('last_attempt_at')->nullable();
        $table->timestamp('next_attempt_at')->nullable();
        $table->timestamp('last_dispatched_at')->nullable();
        $table->timestamp('sent_at')->nullable();
        $table->timestamps();
    });
});

afterEach(function () {
    foreach (['control_completion_notifications', 'activity_log', 'control_responses', 'control_response_drafts', 'apartments', 'controls', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

function controlWorkflowSchema(): array
{
    return [[
        'title' => 'Вход',
        'items' => [[
            'question' => 'Ключи на месте?',
            'answer_type' => 'options',
            'answer_options_scored' => [
                ['value' => 'yes', 'label' => 'Да', 'points' => 1],
                ['value' => 'no', 'label' => 'Нет', 'points' => 0],
            ],
        ]],
    ]];
}

function mountControlWorkflow(): array
{
    $supervisor = User::create(['name' => 'Supervisor', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    $cleaner = User::create(['name' => 'Cleaner', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $apartment = DB::table('apartments')->insertGetId(['name' => 'Apartment', 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $control = Control::create(['name' => 'Control', 'slug' => 'control-workflow', 'main' => controlWorkflowSchema(), 'is_active' => true]);

    test()->actingAs($supervisor);

    return [$supervisor, $cleaner, $apartment, $control];
}

function createControlCompletionNotificationFixture(): array
{
    Queue::fake();
    [, $cleaner, $apartment] = mountControlWorkflow();
    $cleaner->forceFill(['telegram_id' => 'test-recipient-chat'])->save();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    return [
        ControlResponse::query()->sole(),
        ControlCompletionNotification::query()->sole(),
        $cleaner,
    ];
}

function signedControlResultStartData(string $telegramId, string $startParam, string $botToken, ?int $authDate = null): string
{
    $data = [
        'auth_date' => (string) ($authDate ?? now()->timestamp),
        'start_param' => $startParam,
        'user' => json_encode(['id' => (int) $telegramId, 'first_name' => 'Test'], JSON_THROW_ON_ERROR),
    ];
    ksort($data);
    $check = collect($data)->map(fn (string $value, string $key): string => $key.'='.$value)->implode("\n");
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $data['hash'] = hash_hmac('sha256', $check, $secret);

    return http_build_query($data);
}

it('queues one durable completion notification for each Classic and Focus result', function (): void {
    Queue::fake();
    [$supervisor, $cleaner, $apartment] = mountControlWorkflow();
    $cleaner->forceFill(['telegram_id' => 'test-recipient-chat'])->save();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'yes')
        ->call('start')
        ->call('review')
        ->call('submit')
        ->assertSet('submitted', true);

    $notifications = DB::table('control_completion_notifications')->orderBy('control_response_id')->get();

    foreach ($notifications as $notification) {
        app(ControlCompletionNotificationService::class)->queueFor(
            ControlResponse::query()->findOrFail($notification->control_response_id),
        );
    }

    expect($notifications)->toHaveCount(2)
        ->and($notifications->pluck('recipient_user_id')->unique()->all())->toBe([$cleaner->id])
        ->and($notifications->pluck('status')->unique()->all())->toBe(['pending']);

    Queue::assertPushed(SendControlCompletionNotification::class, 2);
    foreach ($notifications as $notification) {
        Queue::assertPushed(
            SendControlCompletionNotification::class,
            fn (SendControlCompletionNotification $job): bool => $job->notificationId === $notification->id,
        );
    }
});

it('sends a single Work Bot result message with a Mini App deep link', function (): void {
    config([
        'services.telegram.bot_token' => 'test-bot-token',
        'services.telegram.bot_username' => 'tris_test_bot',
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response([
        'ok' => true,
        'result' => ['message_id' => 77],
    ])]);

    [$response, $notification, $cleaner] = createControlCompletionNotificationFixture();
    $job = new SendControlCompletionNotification((int) $notification->id);

    $job->handle(app(TelegramBotService::class), app(TelegramOutboundMonitor::class), app(ControlCompletionNotificationService::class));
    $job->handle(app(TelegramBotService::class), app(TelegramOutboundMonitor::class), app(ControlCompletionNotificationService::class));

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
        && data_get($request->data(), 'chat_id') === 'test-recipient-chat'
        && str_contains((string) data_get($request->data(), 'text'), 'Apartment')
        && str_contains((string) data_get($request->data(), 'text'), 'Ошибки:')
        && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.url')
            === 'https://t.me/tris_test_bot?startapp=control_result_'.$response->id,
    );

    expect($notification->fresh()->status)->toBe('sent')
        ->and($notification->fresh()->telegram_message_id)->toBe(77)
        ->and($cleaner->fresh()->telegram_id)->toBe('test-recipient-chat')
        ->and(ControlResponse::query()->whereKey($response->id)->exists())->toBeTrue();
});

it('keeps a completed Classic result when the Work Bot returns an error', function (): void {
    config([
        'services.telegram.bot_token' => 'test-bot-token',
        'services.telegram.bot_username' => 'tris_test_bot',
    ]);
    Http::preventStrayRequests();
    Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false], 502)]);

    [, $cleaner, $apartment] = mountControlWorkflow();
    $cleaner->forceFill(['telegram_id' => 'test-recipient-chat'])->save();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    expect(ControlResponse::query()->count())->toBe(1)
        ->and(ControlCompletionNotification::query()->sole()->status)->toBe('uncertain');
    Http::assertSentCount(1);
});

it('retries a definite Telegram rate limit without duplicating a completed send', function (): void {
    config([
        'services.telegram.bot_token' => 'test-bot-token',
        'services.telegram.bot_username' => 'tris_test_bot',
    ]);
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(['ok' => false, 'parameters' => ['retry_after' => 10]], 429)
        ->push(['ok' => true, 'result' => ['message_id' => 78]], 200);

    [, $notification] = createControlCompletionNotificationFixture();
    $job = new SendControlCompletionNotification((int) $notification->id);
    $telegram = app(TelegramBotService::class);
    $monitor = app(TelegramOutboundMonitor::class);
    $links = app(ControlCompletionNotificationService::class);

    $job->handle($telegram, $monitor, $links);
    expect($notification->fresh()->status)->toBe('pending')
        ->and($notification->fresh()->attempts)->toBe(1)
        ->and($notification->fresh()->next_attempt_at)->not->toBeNull();

    $this->travel(11)->seconds();
    $job->handle($telegram, $monitor, $links);
    $job->handle($telegram, $monitor, $links);

    Http::assertSentCount(2);
    expect($notification->fresh()->status)->toBe('sent')
        ->and($notification->fresh()->attempts)->toBe(2)
        ->and($notification->fresh()->telegram_message_id)->toBe(78);
});

it('marks an ambiguous Telegram timeout uncertain and does not send it again', function (): void {
    config([
        'services.telegram.bot_token' => 'test-bot-token',
        'services.telegram.bot_username' => 'tris_test_bot',
    ]);
    Http::preventStrayRequests();
    $requestAttempts = 0;
    Http::fake(function () use (&$requestAttempts) {
        $requestAttempts++;
        throw new ConnectionException('simulated timeout');
    });

    [$response, $notification] = createControlCompletionNotificationFixture();
    $job = new SendControlCompletionNotification((int) $notification->id);
    $telegram = app(TelegramBotService::class);
    $monitor = app(TelegramOutboundMonitor::class);
    $links = app(ControlCompletionNotificationService::class);

    $job->handle($telegram, $monitor, $links);
    $job->handle($telegram, $monitor, $links);

    expect($notification->fresh()->status)->toBe('uncertain')
        ->and($notification->fresh()->attempts)->toBe(1)
        ->and($requestAttempts)->toBe(1)
        ->and(ControlResponse::query()->whereKey($response->id)->exists())->toBeTrue();
});

it('redispatches stale pending notifications from the outbox scanner', function (): void {
    Queue::fake();
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    $response = ControlResponse::create([
        'control_id' => $control->id,
        'supervisor_id' => auth()->id(),
        'cleaner_id' => $cleaner->id,
        'apartment_id' => $apartment,
        'inspection_date' => now()->toDateString(),
        'responses' => [],
        'schema_snapshot' => $control->main,
        'status' => ControlResponse::STATUS_SENT,
        'sent_at' => now(),
    ]);
    ControlCompletionNotification::create([
        'control_response_id' => $response->id,
        'recipient_user_id' => $cleaner->id,
        'status' => 'pending',
        'last_dispatched_at' => now()->subMinutes(6),
    ]);

    $this->artisan('controls:dispatch-completion-notifications')->assertSuccessful();

    Queue::assertPushed(SendControlCompletionNotification::class, 1);
});

it('opens only the completed result authorized for the signed Mini App user', function (): void {
    [$supervisor, $cleaner, $apartment, $control] = mountControlWorkflow();
    $token = 'test-mini-app-token';
    config(['services.telegram.bot_token' => $token]);
    $cleaner->forceFill(['telegram_id' => '701'])->save();
    $response = ControlResponse::create([
        'control_id' => $control->id,
        'supervisor_id' => $supervisor->id,
        'cleaner_id' => $cleaner->id,
        'apartment_id' => $apartment,
        'inspection_date' => now()->toDateString(),
        'responses' => [],
        'schema_snapshot' => $control->main,
        'status' => ControlResponse::STATUS_SENT,
        'sent_at' => now(),
    ]);

    $this->postJson(route('telegram.auth'), [
        'init_data' => signedControlResultStartData('701', 'control_result_'.$response->id, $token),
    ])
        ->assertOk()
        ->assertJsonPath('redirect', route('page-profile.checks.result', $response));

    $other = User::create([
        'name' => 'Other cleaner',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
        'telegram_id' => '702',
    ]);

    $this->postJson(route('telegram.auth'), [
        'init_data' => signedControlResultStartData((string) $other->telegram_id, 'control_result_'.$response->id, $token),
    ])->assertForbidden();
});

it('rejects expired and future Telegram Mini App initData timestamps', function (): void {
    $token = 'test-mini-app-token';
    config(['services.telegram.bot_token' => $token]);
    $service = app(TelegramMiniAppService::class);

    expect(fn () => $service->validate(signedControlResultStartData('701', '', $token, now()->timestamp - 86401)))
        ->toThrow(HttpException::class);

    expect(fn () => $service->validate(signedControlResultStartData('701', '', $token, now()->timestamp + 120)))
        ->toThrow(HttpException::class);

    $signed = signedControlResultStartData('701', 'control_result_1', $token);
    $tampered = str_replace('control_result_1', 'control_result_2', $signed);
    expect(fn () => $service->validate($tampered))
        ->toThrow(HttpException::class);
});

it('hides corrective details and does not require them for a negative answer', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'no')
        ->call('openReview')
        ->assertHasNoErrors()
        ->assertSet('reviewSheetOpen', true)
        ->assertDontSee('Уточнить исправление')
        ->assertDontSee('Что исправить')
        ->assertSee('Ответ: Нет')
        ->call('confirmSubmit')
        ->assertHasNoErrors()
        ->assertSet('reviewSheetOpen', false)
        ->assertSet('successSheetOpen', true);
});

it('does not acknowledge an empty Classic upload as saved', function () {
    mountControlWorkflow();

    Livewire::test('forms.page-control')
        ->call('finishPhotoUpload', 0, 0)
        ->assertReturned('Файл не получен сервером. Повторите загрузку.');

    expect(DB::table('control_response_drafts')->count())->toBe(0);
});

it('does not acknowledge a malformed Classic temporary file as saved', function () {
    mountControlWorkflow();
    Livewire::test('forms.page-control')->set('photoUploads.0.0', ['missing-file'])
        ->call('finishPhotoUpload', 0, 0)->assertReturned('Файл не получен сервером. Повторите загрузку.');
    expect(ControlResponseDraft::query()->count())->toBe(0);
});

it('cleans a failed Classic media write and safely retries without duplicate physical files', function () {
    mountControlWorkflow();
    Storage::fake('local');
    $id = (string) Str::uuid();
    $component = Livewire::test('forms.page-control')->call('setAnswer', 0, 0, 'no');
    ControlResponseDraft::saving(fn () => throw new RuntimeException('Simulated draft write failure'));
    try {
        $component->set('photoUploads.0.0.'.$id.'_1', UploadedFile::fake()->image('proof.jpg'))
            ->call('finishPhotoUpload', 0, 0, $id, $id.'_1')
            ->assertReturned('Не удалось сохранить файл. Повторите загрузку.');
    } finally {
        ControlResponseDraft::flushEventListeners();
    }
    expect(Storage::disk('local')->allFiles('control-drafts'))->toBe([]);
    $component->assertSet('answers.0.0.selected', 'no')
        ->set('photoUploads.0.0.'.$id.'_2', UploadedFile::fake()->image('proof.jpg'))
        ->call('finishPhotoUpload', 0, 0, $id, $id.'_2')->assertReturned('saved');
    expect(ControlResponseDraft::query()->sole()->responses[0][0]['media'])->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles('control-drafts'))->toHaveCount(1);
});

it('does not duplicate a Classic attachment when its save acknowledgement is retried', function () {
    mountControlWorkflow();
    Storage::fake('local');
    $uploadId = (string) Str::uuid();

    Livewire::test('forms.page-control')
        ->set('photoUploads.0.0.'.$uploadId.'_1', UploadedFile::fake()->image('one.jpg'))
        ->call('finishPhotoUpload', 0, 0, $uploadId, $uploadId.'_1')
        ->set('photoUploads.0.0.'.$uploadId.'_2', UploadedFile::fake()->image('one.jpg'))
        ->call('finishPhotoUpload', 0, 0, $uploadId, $uploadId.'_2');

    $media = json_decode(DB::table('control_response_drafts')->value('responses'), true)[0][0]['media'];
    expect($media)->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles('control-drafts'))->toHaveCount(1);
});

it('retains five Classic photos in the same draft across save acknowledgements and autosave', function () {
    mountControlWorkflow();
    Storage::fake('local');
    $component = Livewire::test('forms.page-control');
    $uploadIds = [];

    for ($i = 1; $i <= 5; $i++) {
        $uploadId = (string) Str::uuid();
        $uploadIds[] = $uploadId;
        $component->set('photoUploads.0.0.'.$uploadId.'_1', UploadedFile::fake()->image('photo'.$i.'.jpg'))
            ->call('finishPhotoUpload', 0, 0, $uploadId, $uploadId.'_1')
            ->assertReturned('saved');
    }

    $component->call('saveDraftAuto');
    $media = json_decode(DB::table('control_response_drafts')->sole()->responses, true)[0][0]['media'];
    expect($media)->toHaveCount(5)
        ->and(array_column($media, 'upload_id'))->toBe($uploadIds)
        ->and(Storage::disk('local')->allFiles('control-drafts'))->toHaveCount(5);
    foreach ($media as $item) {
        Storage::disk('local')->assertExists($item['path']);
    }
    Livewire::test('forms.page-control')->assertSet('answers.0.0.media', $media);
});

it('serializes Classic autosave with a media acknowledgement instead of overwriting its file', function () {
    mountControlWorkflow();
    Storage::fake('local');
    $autosave = Livewire::test('forms.page-control')->set('comment', 'Initial')->call('saveDraftAuto');
    $id = (string) Str::uuid();
    $uploader = Livewire::test('forms.page-control')
        ->set('photoUploads.0.0.'.$id.'_1', UploadedFile::fake()->image('proof.jpg'));
    $interleaved = false;
    $acknowledgement = null;

    ControlResponseDraft::updating(function () use ($uploader, $id, &$interleaved, &$acknowledgement): void {
        if (! $interleaved) {
            $interleaved = true;
            $acknowledgement = $uploader->instance()->finishPhotoUpload(0, 0, $id, $id.'_1');
        }
    });
    try {
        $autosave->set('comment', 'Changed during upload')
            ->set('answers.0.0.custom', 'Answer changed during upload')
            ->call('saveDraftAuto');
    } finally {
        ControlResponseDraft::flushEventListeners();
    }

    if ($acknowledgement !== 'saved') {
        $uploader->call('finishPhotoUpload', 0, 0, $id, $id.'_1')->assertReturned('saved');
    }
    $media = ControlResponseDraft::query()->sole()->responses[0][0]['media'];
    expect($interleaved)->toBeTrue()
        ->and($media)->toHaveCount(1)
        ->and($media[0]['upload_id'])->toBe($id)
        ->and(Storage::disk('local')->size($media[0]['path']))->toBeGreaterThan(0)
        ->and(Storage::disk('local')->allFiles('control-drafts'))->toHaveCount(1)
        ->and($acknowledgement)->toBe('Сохранение ещё выполняется. Повторите попытку.');
    Livewire::test('forms.page-control')->assertSet('answers.0.0.media', $media);
});

it('serializes Classic removal and removes the identified attachment despite shifted indexes', function () {
    [$supervisor, , , $control] = mountControlWorkflow();
    Storage::fake('local');
    $component = Livewire::test('forms.page-control');
    $ids = [];
    foreach (range(1, 3) as $number) {
        $id = $ids[] = (string) Str::uuid();
        $component->set('photoUploads.0.0.'.$id.'_1', UploadedFile::fake()->image('proof'.$number.'.jpg'))
            ->call('finishPhotoUpload', 0, 0, $id, $id.'_1');
    }
    $lock = Cache::lock('control-draft-media:'.$control->id.':'.$supervisor->id, 30);
    $lock->get();
    try {
        $component->call('removeQueuedPhoto', 0, 0, 0, $ids[0]);
        expect(ControlResponseDraft::query()->sole()->responses[0][0]['media'])->toHaveCount(3);
    } finally {
        $lock->release();
    }
    $component->call('removeQueuedPhoto', 0, 0, 0, $ids[0])
        ->call('removeQueuedPhoto', 0, 0, 1, $ids[1]);
    $media = ControlResponseDraft::query()->sole()->responses[0][0]['media'];
    expect(array_column($media, 'upload_id'))->toBe([$ids[2]])
        ->and(Storage::disk('local')->allFiles('control-drafts'))->toHaveCount(1);
});

it('retains all twelve Classic attachments and rejects a thirteenth without deleting prior files', function () {
    mountControlWorkflow();
    Storage::fake('local');
    $component = Livewire::test('forms.page-control');
    foreach (range(1, 12) as $number) {
        $id = (string) Str::uuid();
        $component->set('photoUploads.0.0.'.$id.'_1', UploadedFile::fake()->image('proof'.$number.'.jpg'))
            ->call('finishPhotoUpload', 0, 0, $id, $id.'_1')->assertReturned('saved');
    }
    $id = (string) Str::uuid();
    $component->set('photoUploads.0.0.'.$id.'_1', UploadedFile::fake()->image('extra.jpg'))
        ->call('finishPhotoUpload', 0, 0, $id, $id.'_1')->assertReturned('Максимум 12 файлов на один вопрос');
    $media = ControlResponseDraft::query()->sole()->responses[0][0]['media'];
    expect($media)->toHaveCount(12)
        ->and(array_unique(array_column($media, 'upload_id')))->toHaveCount(12)
        ->and(Storage::disk('local')->allFiles('control-drafts'))->toHaveCount(12);
    foreach ($media as $file) {
        expect(Storage::disk('local')->size($file['path']))->toBe($file['size']);
    }
});

it('preserves real Classic video bytes through reload, finalization, Range and authorized download', function (string $extension, string $mime) {
    [$supervisor, $cleaner, $apartment, $control] = mountControlWorkflow();
    Storage::fake('local');
    $path = base_path('tests/Fixtures/ControlMedia/green.'.$extension);
    $id = (string) Str::uuid();
    $component = Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)->set('apartment_id', $apartment)
        ->set('photoUploads.0.0.'.$id.'_1', UploadedFile::fake()->createWithContent('clip.'.$extension, file_get_contents($path))
            ->mimeType((new finfo(FILEINFO_MIME_TYPE))->file($path)))
        ->call('finishPhotoUpload', 0, 0, $id, $id.'_1')->assertReturned('saved');
    $media = ControlResponseDraft::query()->sole()->responses[0][0]['media'];
    expect($media[0]['mime'])->toBe($mime)
        ->and(hash('sha256', Storage::disk('local')->get($media[0]['path'])))->toBe(hash_file('sha256', $path));
    $draftUrl = route('page-checks.control.draft-media', [$control, 0, 0, 0]);
    $this->get($draftUrl, ['Range' => 'bytes=0-31'])->assertStatus(206)->assertHeader('Content-Length', '32')->assertHeader('Content-Type', $mime);
    $this->get($draftUrl.'?download=1')->assertDownload('clip.'.$extension);
    $other = User::create(['name' => 'Other supervisor', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    $this->actingAs($other)->get($draftUrl)->assertNotFound();
    Livewire::test('forms.page-control')->call('removeQueuedPhoto', 0, 0, 0);
    Storage::disk('local')->assertExists($media[0]['path']);
    $this->actingAs($supervisor);
    Livewire::test('forms.page-control')->assertSet('answers.0.0.media', $media)
        ->call('setAnswer', 0, 0, 'yes')->call('confirmSubmit')->assertSet('successSheetOpen', true);
    $result = ControlResponse::query()->sole();
    $saved = $result->responses[0][0]['media'];
    expect($saved)->toHaveCount(1)
        ->and(hash('sha256', Storage::disk('local')->get($saved[0]['path'])))->toBe(hash_file('sha256', $path));
    Storage::disk('local')->assertMissing($media[0]['path']);
    $url = route('page-checks.control.result-media', [$result, 0, 0, 0]);
    $this->get($url, ['Range' => 'bytes=0-31'])->assertStatus(206)->assertHeader('Content-Type', $mime);
    $this->actingAs($other)->get($url)->assertOk();
    $this->actingAs($cleaner)->get($url.'?download=1')->assertDownload('clip.'.$extension);
})->with([['mp4', 'video/mp4'], ['mov', 'video/quicktime'], ['webm', 'video/webm']]);

it('keeps committed Classic media when the completed cache marker fails', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();
    Storage::fake('local');
    $component = Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'yes')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('proof.jpg')])
        ->call('finishPhotoUpload', 0, 0);

    Cache::extend('control-marker-failure', fn () => Cache::repository(new class extends ArrayStore
    {
        public function put($key, $value, $seconds)
        {
            if (str_ends_with($key, ':completed')) {
                throw new RuntimeException('Synthetic cache write failure');
            }

            return parent::put($key, $value, $seconds);
        }
    })
    );
    config(['cache.default' => 'control-marker-failure', 'cache.stores.control-marker-failure' => ['driver' => 'control-marker-failure']]);

    $staleComponent = Livewire::test('forms.page-control');
    $component->call('submit')->assertSet('successSheetOpen', true);
    $response = ControlResponse::query()->sole();
    expect($response->status)->toBe(ControlResponse::STATUS_SENT);
    Storage::disk('local')->assertExists($response->responses[0][0]['media'][0]['path']);
    $staleComponent->call('submit');
    expect(ControlResponse::query()->count())->toBe(1);
});

it('does not acknowledge a late Classic attachment between result creation and draft cleanup', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();
    Storage::fake('local');
    $component = Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('saved.jpg')])
        ->call('finishPhotoUpload', 0, 0);
    $id = (string) Str::uuid();
    $lateUpload = Livewire::test('forms.page-control')
        ->set('photoUploads.0.0.'.$id.'_1', UploadedFile::fake()->image('late.jpg'));
    $ack = null;
    ControlResponse::created(function () use ($lateUpload, $id, &$ack): void {
        $ack = $lateUpload->instance()->finishPhotoUpload(0, 0, $id, $id.'_1');
    });
    try {
        $component->call('confirmSubmit')->assertSet('successSheetOpen', true);
    } finally {
        ControlResponse::flushEventListeners();
    }
    expect($ack)->toBe('Сохранение ещё выполняется. Повторите попытку.')
        ->and(ControlResponseDraft::query()->count())->toBe(0)
        ->and(ControlResponse::query()->sole()->responses[0][0]['media'])->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles('controls'))->toHaveCount(1);
});

it('stores actual PNG and WebP bytes without changing existing Classic answers', function (string $extension, string $mime) {
    mountControlWorkflow();
    Storage::fake('local');
    $image = UploadedFile::fake()->image('proof.'.$extension, 40, 30);
    $bytes = file_get_contents($image->getPathname());
    expect((new finfo(FILEINFO_MIME_TYPE))->buffer($bytes))->toBe($mime);
    Livewire::test('forms.page-control')->call('setAnswer', 0, 0, 'no')
        ->set('photoUploads.0.0', [$image])->call('finishPhotoUpload', 0, 0)->assertReturned('saved');
    $draft = ControlResponseDraft::query()->sole()->responses[0][0];
    expect($draft['selected'])->toBe('no')
        ->and(hash('sha256', Storage::disk('local')->get($draft['media'][0]['path'])))->toBe(hash('sha256', $bytes));
    Livewire::test('forms.page-control')->assertSet('answers.0.0.selected', 'no')->assertCount('answers.0.0.media', 1);
})->with([['png', 'image/png'], ['webp', 'image/webp']]);

it('preserves loader preview navigation and safely loads the overlay on ordinary pages', function () {
    [$supervisor] = mountControlWorkflow();

    auth()->logout();

    $this->get('/?loader_preview=1')
        ->assertRedirect(route('landing.page', ['loader_preview' => '1']));

    $this->actingAs($supervisor)
        ->get('/?loader_preview=1')
        ->assertRedirect(route('page-home', ['loader_preview' => '1']));

    $badge = Mockery::mock(UserApplicationBadgeService::class);
    $badge->shouldReceive('label')->twice()->andReturnNull();
    app()->instance(UserApplicationBadgeService::class, $badge);

    $this->actingAs($supervisor)
        ->get('/checks/control?loader_preview=1')
        ->assertOk()
        ->assertSee('loader-preview-overlay')
        ->assertSee('background: transparent;', false);

    $this->get('/checks/control')
        ->assertOk()
        ->assertSee('loader-preview-overlay')
        ->assertSee('visibility: hidden;', false)
        ->assertSee('window.setTimeout(dismiss, 8000)', false);
});

it('moves to the next unanswered required question and opens review only when required answers are complete', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    $control->main = [
        [
            'title' => 'Вход',
            'items' => [
                ['question' => 'Первый вопрос?', 'answer_type' => 'options', 'answer_options_scored' => [['value' => 'yes', 'label' => 'Да', 'points' => 1]]],
                ['question' => 'Второй вопрос?', 'answer_type' => 'options', 'answer_options_scored' => [['value' => 'yes', 'label' => 'Да', 'points' => 1]]],
                ['question' => 'Необязательный вопрос?', 'is_optional' => true, 'answer_type' => 'options', 'answer_options_scored' => [['value' => 'yes', 'label' => 'Да', 'points' => 1]]],
            ],
        ],
        [
            'title' => 'Кухня',
            'items' => [
                ['question' => 'Третий вопрос?', 'answer_type' => 'options', 'answer_options_scored' => [['value' => 'yes', 'label' => 'Да', 'points' => 1]]],
            ],
        ],
    ];
    $control->save();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->assertSet('incompleteQuestionsCount', 3)
        ->assertSee('Дальше')
        ->call('continueForm')
        ->assertSet('openRoomIndex', 0)
        ->assertSet('reviewSheetOpen', false)
        ->assertSet('attemptedSubmit', false)
        ->assertHasNoErrors()
        ->call('setAnswer', 0, 0, 'yes')
        ->call('continueForm')
        ->assertSet('openRoomIndex', 0)
        ->call('setAnswer', 0, 1, 'yes')
        ->call('openRoom', 0)
        ->call('continueForm')
        ->assertSet('openRoomIndex', 1)
        ->assertSet('reviewSheetOpen', false)
        ->assertSet('attemptedSubmit', false)
        ->call('setAnswer', 1, 0, 'yes')
        ->assertSee('Проверить контроль')
        ->call('continueForm')
        ->assertSet('reviewSheetOpen', true)
        ->assertHasNoErrors();
});

it('resets the current control answers, draft, and related UI state', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    $component = Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'no')
        ->set('comment', 'Черновой комментарий')
        ->set('attemptedSubmit', true)
        ->set('reviewSheetOpen', true)
        ->set('successSheetOpen', true)
        ->call('saveDraft');

    expect(DB::table('control_response_drafts')->count())->toBe(1);

    $component->call('resetControl')
        ->assertSet('cleaner_id', null)
        ->assertSet('apartment_id', null)
        ->assertSet('comment', '')
        ->assertSet('answers.0.0.selected', '')
        ->assertSet('attemptedSubmit', false)
        ->assertSet('reviewSheetOpen', false)
        ->assertSet('successSheetOpen', false)
        ->assertSet('successMessage', null)
        ->assertSet('draftId', null)
        ->assertSet('draftState', 'idle')
        ->assertSet('hasUnsavedChanges', false)
        ->assertHasNoErrors();

    expect(DB::table('control_response_drafts')->count())->toBe(0);
});

it('lists unanswered required questions in the final check and opens their room', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('openReview')
        ->assertSet('reviewSheetOpen', true)
        ->assertSee('Пунктов для проверки: 1')
        ->assertSee('Ответьте на вопрос')
        ->assertSee('Перейти к вопросу')
        ->call('goToQuestion', 0, 0)
        ->assertSet('reviewSheetOpen', false)
        ->assertSet('openRoomIndex', 0);
});

it('persists corrective fields in a draft and restores them on reload', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'no')
        ->set('answers.0.0.corrective.repeats', true)
        ->set('answers.0.0.corrective.action', 'Проверить замок ещё раз')
        ->set('answers.0.0.corrective.recheck', false)
        ->call('saveDraft');

    expect(json_decode((string) DB::table('control_response_drafts')->value('responses'), true)[0][0]['corrective'])
        ->toMatchArray(['repeats' => true, 'action' => 'Проверить замок ещё раз', 'recheck' => false]);

    Livewire::test('forms.page-control')
        ->assertSet('answers.0.0.corrective.repeats', true)
        ->assertSet('answers.0.0.corrective.action', 'Проверить замок ещё раз')
        ->assertSet('answers.0.0.corrective.recheck', false);
});

it('saves corrective fields for the matching problem on submit', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'no')
        ->set('answers.0.0.corrective.repeats', false)
        ->set('answers.0.0.corrective.action', 'Исправить замок')
        ->set('answers.0.0.corrective.recheck', true)
        ->call('openReview')
        ->assertSet('reviewSheetOpen', true)
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    $responses = json_decode((string) DB::table('control_responses')->value('responses'), true);

    expect($responses[0][0]['corrective'])
        ->toMatchArray(['repeats' => false, 'action' => 'Исправить замок', 'recheck' => true]);
});

it('stores a short video with its Classic control question', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();
    Storage::fake('public');
    Storage::fake('local');

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'yes')
        ->set('photoUploads.0.0', [UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4')])
        ->call('finishPhotoUpload', 0, 0)
        ->assertReturned('saved')
        ->assertCount('answers.0.0.media', 1)
        ->call('openReview')
        ->assertSet('reviewSheetOpen', true)
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    $responses = json_decode((string) DB::table('control_responses')->value('responses'), true);
    expect($responses[0][0]['media'])->toHaveCount(1)
        ->and($responses[0][0]['media'][0]['mime'])->toBe('video/mp4');
    expect($responses[0][0]['media'][0]['disk'])->toBe('local');
    Storage::disk('local')->assertExists($responses[0][0]['media'][0]['path']);
});

it('keeps sequential videos in the draft and result when a later upload is rejected', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();
    Storage::fake('local');
    $firstId = (string) Str::uuid();
    $secondId = (string) Str::uuid();

    $component = Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'yes')
        ->set('photoUploads.0.0.'.$firstId.'_1', UploadedFile::fake()->create('first.webm', 32, 'video/webm'))
        ->call('finishPhotoUpload', 0, 0, $firstId, $firstId.'_1')
        ->assertReturned('saved')
        ->set('photoUploads.0.0.'.$secondId.'_1', UploadedFile::fake()->create('second.webm', 48, 'video/webm'))
        ->call('finishPhotoUpload', 0, 0, $secondId, $secondId.'_1')
        ->assertReturned('saved')
        ->set('photoUploads.0.0', [UploadedFile::fake()->create('rejected.pdf', 8, 'application/pdf')])
        ->call('finishPhotoUpload', 0, 0);

    $draftMedia = json_decode((string) DB::table('control_response_drafts')->value('responses'), true)[0][0]['media'];
    expect($draftMedia)->toHaveCount(2)
        ->and(collect($draftMedia)->pluck('upload_id')->all())->toBe([$firstId, $secondId]);
    Storage::disk('local')->assertExists($draftMedia[0]['path']);
    Storage::disk('local')->assertExists($draftMedia[1]['path']);

    $component->assertReturned('Можно загружать только фото и видео')
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    $resultMedia = ControlResponse::query()->sole()->responses[0][0]['media'];
    expect($resultMedia)->toHaveCount(2)
        ->and(collect($resultMedia)->pluck('original_name')->all())->toBe(['first.webm', 'second.webm']);
    foreach ($resultMedia as $file) {
        Storage::disk('local')->assertExists($file['path']);
    }
});

it('isolates saved media between consecutive apartment controls in one Livewire session', function () {
    [, $cleaner, $firstApartment] = mountControlWorkflow();
    Storage::fake('local');
    $secondApartment = DB::table('apartments')->insertGetId([
        'name' => 'Second apartment', 'is_active' => true, 'sort_order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $component = Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $firstApartment)
        ->set('answers.0.0.selected', 'yes')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('Bramante-A.jpg')])
        ->call('finishPhotoUpload', 0, 0)
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true)
        ->call('resetControl')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $secondApartment)
        ->set('answers.0.0.selected', 'yes')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('Mercato-B.jpg')])
        ->call('finishPhotoUpload', 0, 0)
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    $responses = ControlResponse::query()->orderBy('id')->get();
    expect($responses)->toHaveCount(2)
        ->and($responses[0]->apartment_id)->toBe($firstApartment)
        ->and($responses[1]->apartment_id)->toBe($secondApartment)
        ->and($responses[0]->responses[0][0]['media'][0]['original_name'])->toBe('Bramante-A.jpg')
        ->and($responses[1]->responses[0][0]['media'][0]['original_name'])->toBe('Mercato-B.jpg')
        ->and($responses[0]->responses[0][0]['media'][0]['path'])->not->toBe($responses[1]->responses[0][0]['media'][0]['path']);
});

it('rejects unsupported Classic attachments', function () {
    mountControlWorkflow();
    Storage::fake('local');

    Livewire::test('forms.page-control')
        ->set('photoUploads.0.0', [UploadedFile::fake()->create('document.pdf', 50, 'application/pdf')])
        ->call('finishPhotoUpload', 0, 0)
        ->assertReturned('Можно загружать только фото и видео')
        ->assertCount('answers.0.0.media', 0)
        ->assertHasErrors('queuedPhotos.0.0');
});

it('recovers from a failed optional Classic upload without losing answers or blocking submission', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();
    Storage::fake('local');

    $component = Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'yes')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('first.jpg')])
        ->call('finishPhotoUpload', 0, 0)
        ->assertReturned('saved')
        ->set('photoUploads.0.0', [UploadedFile::fake()->create('document.pdf', 50, 'application/pdf')])
        ->call('finishPhotoUpload', 0, 0)
        ->assertReturned('Можно загружать только фото и видео')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('too-big.jpg')->size(11000)])
        ->call('finishPhotoUpload', 0, 0)
        ->assertReturned('Один файл не должен быть больше 10 МБ')
        ->assertCount('answers.0.0.media', 1)
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('retry.jpg')])
        ->call('finishPhotoUpload', 0, 0)
        ->assertReturned('saved')
        ->assertCount('answers.0.0.media', 2);

    $component->call('removeQueuedPhoto', 0, 0, 0)
        ->call('removeQueuedPhoto', 0, 0, 0)
        ->assertCount('answers.0.0.media', 0)
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    expect(DB::table('control_responses')->count())->toBe(1)
        ->and(json_decode((string) DB::table('control_responses')->value('responses'), true)[0][0]['selected'])->toBe('yes');
});

it('restores, removes and submits Classic draft media without accepting a foreign path', function () {
    [$supervisor, $cleaner, $apartment, $control] = mountControlWorkflow();
    Storage::fake('local');
    Storage::fake('public');

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'yes')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('first.jpg')])
        ->call('finishPhotoUpload', 0, 0)
        ->set('photoUploads.0.0', [UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4')])
        ->call('finishPhotoUpload', 0, 0)
        ->call('saveDraft')
        ->assertCount('answers.0.0.media', 2);

    $draftMedia = json_decode((string) DB::table('control_response_drafts')->value('responses'), true)[0][0]['media'];
    expect($draftMedia)->toHaveCount(2);
    Storage::disk('local')->assertExists($draftMedia[0]['path']);
    Storage::disk('local')->assertExists($draftMedia[1]['path']);
    $draftUrl = route('page-checks.control.draft-media', [$control, 0, 0, 0]);
    $this->get($draftUrl)->assertOk();

    $otherSupervisor = User::create(['name' => 'Other', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    $this->actingAs($otherSupervisor)->get($draftUrl)->assertNotFound();
    Livewire::actingAs($otherSupervisor)->test('forms.page-control')
        ->assertCount('answers.0.0.media', 0)
        ->call('removeQueuedPhoto', 0, 0, 0);
    Storage::disk('local')->assertExists($draftMedia[0]['path']);

    Livewire::actingAs($supervisor)->test('forms.page-control')
        ->assertCount('answers.0.0.media', 2)
        ->call('removeQueuedPhoto', 0, 0, 0)
        ->assertCount('answers.0.0.media', 1)
        ->set('answers.0.0.media', [['disk' => 'public', 'path' => 'controls/'.$control->id.'/foreign.jpg']])
        ->call('saveDraft')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('replacement.jpg')])
        ->call('finishPhotoUpload', 0, 0)
        ->assertCount('answers.0.0.media', 2)
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    Storage::disk('local')->assertMissing($draftMedia[0]['path']);
    Storage::disk('local')->assertMissing($draftMedia[1]['path']);
    $response = ControlResponse::query()->sole();
    $savedMedia = $response->responses[0][0]['media'];
    expect($savedMedia)->toHaveCount(2)
        ->and(collect($savedMedia)->pluck('mime')->all())->toContain('video/mp4', 'image/jpeg')
        ->and(collect($savedMedia)->pluck('path')->all())->not->toContain('controls/'.$control->id.'/foreign.jpg')
        ->and($response->mediaItems())->toHaveCount(1)
        ->and($response->score_percent)->toBe(100)
        ->and(DB::table('control_response_drafts')->count())->toBe(0);
    foreach ($savedMedia as $media) {
        expect($media['disk'])->toBe('local');
        Storage::disk('local')->assertExists($media['path']);
    }

    $resultUrl = route('page-checks.control.result-media', [$response, 0, 0, 0]);
    $this->actingAs($supervisor)->get($resultUrl)->assertOk();
    $this->actingAs($otherSupervisor)->get($resultUrl)->assertOk();
    $this->actingAs($cleaner)->get($resultUrl)->assertOk();
});

it('persists distinct Classic media comments across draft restore and final submission, then reopens the stored JSON result', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();
    $this->withoutVite();
    Storage::fake('local');

    $component = Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('comment', 'Общее замечание к контролю')
        ->set('answers.0.0.selected', 'yes')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('first.jpg')])
        ->call('finishPhotoUpload', 0, 0)
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('second.jpg')])
        ->call('finishPhotoUpload', 0, 0)
        ->call('saveMediaComment', 0, 0, 0, '  Следы пыли на полке  ')
        ->call('saveMediaComment', 0, 0, 1, 'Скол на поверхности')
        ->call('openReview')
        ->assertSee('Следы пыли на полке')
        ->assertSee('Скол на поверхности')
        ->call('saveDraft');

    $draftMedia = ControlResponseDraft::query()->sole()->responses[0][0]['media'];
    expect($draftMedia)->toHaveCount(2)
        ->and($draftMedia[0]['comment'])->toBe('Следы пыли на полке')
        ->and($draftMedia[1]['comment'])->toBe('Скол на поверхности');

    Livewire::test('forms.page-control')
        ->assertSet('answers.0.0.media.0.comment', 'Следы пыли на полке')
        ->assertSet('answers.0.0.media.1.comment', 'Скол на поверхности')
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    $response = ControlResponse::query()->sole();
    expect($response->responses[0][0]['media'][0]['comment'])->toBe('Следы пыли на полке')
        ->and($response->responses[0][0]['media'][1]['comment'])->toBe('Скол на поверхности')
        ->and($response->comment)->toBe('Общее замечание к контролю');

    $existingJson = $response->responses;
    $existingJson[0][0]['media'][0]['comment'] = 'Замечание из уже сохранённого JSON';
    $response->forceFill(['responses' => $existingJson])->save();

    $resultUrl = route('page-profile.checks.result', $response);
    $badge = Mockery::mock(UserApplicationBadgeService::class);
    $badge->shouldReceive('label')->andReturnNull();
    app()->instance(UserApplicationBadgeService::class, $badge);
    foreach (['day_off_requests', 'vacation_requests', 'inventory_requests', 'salary_questions', 'schedule_questions', 'feedback_suggestions'] as $tableName) {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function ($table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('answer_seen_at')->nullable();
                $table->timestamp('answered_at')->nullable();
                $table->text('admin_comment')->nullable();
                $table->string('status')->nullable();
            });
        }
    }

    $this->actingAs($cleaner)->get($resultUrl)
        ->assertOk()
        ->assertSee('Замечание из уже сохранённого JSON')
        ->assertSee('Скол на поверхности')
        ->assertSee('Общее замечание к контролю');

    $unauthorized = User::create(['name' => 'Unauthorized', 'role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    $this->actingAs($unauthorized)->get($resultUrl)->assertOk();
    $otherCleaner = User::create(['name' => 'Other cleaner', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $this->actingAs($otherCleaner)->get($resultUrl)->assertForbidden();
});

it('keeps empty photo comments absent and preserves the overall control comment', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();
    Storage::fake('local');

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('comment', 'Общее замечание')
        ->set('answers.0.0.selected', 'yes')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('without-comment.jpg')])
        ->call('finishPhotoUpload', 0, 0)
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    $response = ControlResponse::query()->sole();
    expect($response->responses[0][0]['media'][0])->not->toHaveKey('comment')
        ->and($response->comment)->toBe('Общее замечание');
});

it('keeps Focus separate from Classic and restores the same server draft', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();

    expect(route('page-checks.control.focus'))->toContain('/checks/control/focus');

    Livewire::test('forms.page-control-focus')
        ->assertSet('stage', 'setup')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('start')
        ->assertSet('stage', 'questions')
        ->call('setAnswer', 0, 0, 'yes')
        ->call('review')
        ->assertSet('stage', 'review');

    expect(DB::table('control_response_drafts')->where('control_id', $control->id)->count())->toBe(1);

    Livewire::test('forms.page-control-focus')
        ->assertSet('answers.0.0.selected', 'yes')
        ->assertSet('cleaner_id', $cleaner->id)
        ->assertSet('apartment_id', $apartment);

    Livewire::test('forms.page-control')->assertSet('answers.0.0.selected', 'yes');
});

it('persists multiple Focus edits in one confirmed draft revision', function () {
    mountControlWorkflow();

    $component = Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', 2)
        ->set('comment', 'Initial check')
        ->set('answers.0.0.selected', 'yes');

    expect(DB::table('control_response_drafts')->count())->toBe(0);

    $component->call('saveDraft')
        ->assertSet('draftRevision', 1)
        ->assertSet('draftState', 'saved');

    $draft = DB::table('control_response_drafts')->sole();
    $responses = json_decode((string) $draft->responses, true);

    expect((int) $draft->revision)->toBe(1)
        ->and($draft->comment)->toBe('Initial check')
        ->and($responses[0][0]['selected'])->toBe('yes');
});

it('writes the Focus schema snapshot only when it first becomes available', function () {
    [, , , $control] = mountControlWorkflow();
    $focus = Livewire::test('forms.page-control-focus')->call('saveDraft');
    $originalSnapshot = ControlResponseDraft::query()->sole()->schema_snapshot;
    $draftUpdates = [];

    DB::listen(function ($query) use (&$draftUpdates): void {
        if (str_starts_with(strtolower($query->sql), 'update "control_response_drafts"')) {
            $draftUpdates[] = $query->sql;
        }
    });

    $focus->call('setAnswer', 0, 0, 'yes');

    expect(ControlResponseDraft::query()->sole()->schema_snapshot)->toBe($originalSnapshot)
        ->and($draftUpdates)->not->toBeEmpty()
        ->and(collect($draftUpdates)->every(fn (string $sql): bool => ! str_contains($sql, 'schema_snapshot')))->toBeTrue();
});

it('shows all questions in the active Focus room and preserves configured question types and answers', function () {
    [, , , $control] = mountControlWorkflow();
    $control->update(['main' => [[
        'title' => 'Ванная',
        'items' => [
            [
                'question' => 'Полотенца на месте?',
                'answer_type' => 'options',
                'answer_options_scored' => [
                    ['value' => 'complete', 'label' => 'Все на месте', 'points' => 1],
                    ['value' => 'missing', 'label' => 'Не хватает', 'points' => 0],
                ],
            ],
            ['question' => 'Комментарий по ванной', 'answer_type' => 'text'],
            [
                'question' => 'Смеситель исправен?',
                'answer_type' => 'both',
                'custom_answer_label' => 'Что именно не так?',
                'answer_options_scored' => [['value' => 'ok', 'label' => 'Работает', 'points' => 1]],
            ],
        ],
    ], [
        'title' => 'Кухня',
        'items' => [['question' => 'Плита чистая?', 'answer_type' => 'text']],
    ]]]);

    Livewire::test('forms.page-control-focus')
        ->call('start')
        ->assertSet('currentRoomIndex', 0)
        ->assertSee('Полотенца на месте?')
        ->assertSee('Комментарий по ванной')
        ->assertSee('Смеситель исправен?')
        ->assertSee('Все на месте')
        ->assertSee('Что именно не так?')
        ->call('setAnswer', 0, 0, 'complete')
        ->set('answers.0.1.custom', 'Без замечаний')
        ->call('goToRoom', 1)
        ->assertSet('currentRoomIndex', 1)
        ->call('goToRoom', 0)
        ->assertSet('currentRoomIndex', 0)
        ->assertSet('answers.0.0.selected', 'complete')
        ->assertSet('answers.0.1.custom', 'Без замечаний');
});

it('clears pending Focus upload state when an upload is cancelled', function () {
    mountControlWorkflow();

    Livewire::test('forms.page-control-focus')
        ->call('beginMediaUpload')
        ->assertSet('pendingMediaCount', 1)
        ->call('cancelMediaUpload')
        ->assertSet('pendingMediaCount', 0)
        ->assertHasNoErrors('mediaUpload');
});

it('submits a negative Focus answer without corrective UI while preserving existing scoring once', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    $focus = Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('start')
        ->call('setAnswer', 0, 0, 'no')
        ->assertDontSee('Что исправить')
        ->assertDontSee('К проверке ответов')
        ->assertSee('Завершить контроль')
        ->call('review', 0)
        ->assertSet('stage', 'review')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('stage', 'done')
        ->assertSet('submitted', true)
        ->call('submit');

    expect(DB::table('control_responses')->count())->toBe(1)
        ->and(DB::table('control_response_drafts')->count())->toBe(0)
        ->and(DB::table('control_responses')->value('penalty_points'))->toBe(1)
        ->and(DB::table('control_responses')->value('result_zone'))->toBe('yellow');
});

it('keeps previously saved corrective data when Focus hides that UI', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'no')
        ->set('answers.0.0.corrective.repeats', true)
        ->set('answers.0.0.corrective.action', 'Проверить ключи')
        ->set('answers.0.0.corrective.recheck', false)
        ->call('saveDraft');

    Livewire::test('forms.page-control-focus')
        ->assertSet('answers.0.0.corrective.action', 'Проверить ключи')
        ->call('start')
        ->assertDontSee('Что исправить')
        ->call('review', 0)
        ->call('submit')
        ->assertSet('submitted', true);

    $saved = json_decode((string) DB::table('control_responses')->value('responses'), true);
    expect($saved[0][0]['corrective'])->toMatchArray([
        'repeats' => true, 'action' => 'Проверить ключи', 'recheck' => false,
    ]);
});

it('attaches Focus photo and video to the exact question and restores media in its draft', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    Storage::fake('local');

    $focus = Livewire::test('forms.page-control-focus')
        ->call('beginMediaUpload')
        ->set('mediaUpload', UploadedFile::fake()->image('room.jpg'))
        ->call('attachMedia', 0, 0)
        ->call('beginMediaUpload')
        ->set('mediaUpload', UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4'))
        ->call('attachMedia', 0, 0)
        ->assertSet('pendingMediaCount', 0);

    $media = json_decode((string) DB::table('control_response_drafts')->where('control_id', $control->id)->value('responses'), true)[0][0]['media'];
    expect($media)->toHaveCount(2)
        ->and($media[0]['mime'])->toBe('image/jpeg')
        ->and($media[1]['mime'])->toBe('video/mp4');
    expect($media[0]['disk'])->toBe('local')->and($media[1]['disk'])->toBe('local');
    Storage::disk('local')->assertExists($media[0]['path']);
    Storage::disk('local')->assertExists($media[1]['path']);

    Livewire::test('forms.page-control-focus')->assertCount('savedMedia.0.0', 2);
    $focus->call('removeMedia', 0, 0, 0)->assertCount('savedMedia.0.0', 1);
    Storage::disk('local')->assertMissing($media[0]['path']);

    $focus->set('cleaner_id', $cleaner->id)->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')->call('review')->call('submit')->assertSet('submitted', true);
    $saved = json_decode((string) DB::table('control_responses')->value('responses'), true);
    expect($saved[0][0]['media'])->toHaveCount(1)
        ->and($saved[0][0]['media'][0]['mime'])->toBe('video/mp4');
    Storage::disk('local')->assertExists($saved[0][0]['media'][0]['path']);
});

it('persists Focus media comments without unlocking saved media metadata', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    Storage::fake('local');

    $focus = Livewire::test('forms.page-control-focus')
        ->call('beginMediaUpload')
        ->set('mediaUpload', UploadedFile::fake()->image('first.jpg'))
        ->call('attachMedia', 0, 0)
        ->call('beginMediaUpload')
        ->set('mediaUpload', UploadedFile::fake()->image('second.jpg'))
        ->call('attachMedia', 0, 0)
        ->call('saveMediaComment', 0, 0, 0, 'Первое замечание')
        ->call('saveMediaComment', 0, 0, 1, 'Второе замечание');

    $draftMedia = ControlResponseDraft::query()->where('control_id', $control->id)->sole()->responses[0][0]['media'];
    expect($draftMedia)->toHaveCount(2)
        ->and($draftMedia[0]['comment'])->toBe('Первое замечание')
        ->and($draftMedia[1]['comment'])->toBe('Второе замечание');

    $completed = Livewire::test('forms.page-control-focus')
        ->assertSet('savedMedia.0.0.0.comment', 'Первое замечание')
        ->assertSet('savedMedia.0.0.1.comment', 'Второе замечание')
        ->call('review')
        ->assertSee('Первое замечание')
        ->assertSee('Второе замечание')
        ->call('backToSetup')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')
        ->call('review')
        ->call('submit')
        ->assertSet('submitted', true)
        ->call('saveMediaComment', 0, 0, 0, 'Позднее изменение')
        ->assertSet('savedMedia.0.0.0.comment', 'Первое замечание');

    $saved = ControlResponse::query()->sole()->responses[0][0]['media'];
    expect($saved)->toHaveCount(2)
        ->and($saved[0]['comment'])->toBe('Первое замечание')
        ->and($saved[1]['comment'])->toBe('Второе замечание')
        ->and($saved[0]['path'])->toBe($draftMedia[0]['path'])
        ->and($saved[1]['path'])->toBe($draftMedia[1]['path'])
        ->and(ControlResponseDraft::query()->count())->toBe(0);
});

it('keeps new Focus media private and serves it only through ownership checked routes', function () {
    [$supervisor, $cleaner, , $control] = mountControlWorkflow();
    Storage::fake('local');
    Storage::fake('public');

    Livewire::test('forms.page-control-focus')
        ->call('beginMediaUpload')
        ->set('mediaUpload', UploadedFile::fake()->image('private.jpg'))
        ->call('attachMedia', 0, 0);

    $draft = ControlResponseDraft::query()->sole();
    $media = $draft->responses[0][0]['media'][0];
    $draftUrl = route('page-checks.control.draft-media', [
        $control, 0, 0, 0, 'draft_id' => $draft->id,
    ]);

    expect($media['disk'])->toBe('local');
    Storage::disk('local')->assertExists($media['path']);
    Storage::disk('public')->assertMissing($media['path']);
    $this->get($draftUrl)->assertOk();

    $otherCleaner = User::create(['name' => 'Other Cleaner', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $this->actingAs($otherCleaner)->get($draftUrl)->assertForbidden();

    $this->actingAs($supervisor);
    Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', DB::table('apartments')->value('id'))
        ->call('setAnswer', 0, 0, 'yes')
        ->call('review')
        ->call('submit')
        ->assertSet('submitted', true);

    $response = ControlResponse::query()->sole();
    $resultUrl = route('page-checks.control.result-media', [$response, 0, 0, 0]);
    $this->actingAs($cleaner)->get($resultUrl)->assertOk();
    $this->actingAs($otherCleaner)->get($resultUrl)->assertForbidden();
});

it('treats a retried Focus upload acknowledgement as idempotent', function () {
    [, , , $control] = mountControlWorkflow();
    Storage::fake('local');
    $uploadId = 'client-retry-7f92';

    $focus = Livewire::test('forms.page-control-focus')
        ->call('beginMediaUpload')
        ->set('mediaUpload', UploadedFile::fake()->image('first.jpg'))
        ->call('attachMedia', 0, 0, $uploadId);
    $first = ControlResponseDraft::query()->sole();
    $firstMedia = $first->responses[0][0]['media'];

    $focus->call('beginMediaUpload')
        ->set('mediaUpload', UploadedFile::fake()->image('retry.jpg'))
        ->call('attachMedia', 0, 0, $uploadId)
        ->assertSet('pendingMediaCount', 0)
        ->assertSet('draftRevision', 1);

    $storedMedia = ControlResponseDraft::query()->sole()->responses[0][0]['media'];
    expect($storedMedia)->toHaveCount(1)
        ->and($storedMedia[0]['path'])->toBe($firstMedia[0]['path'])
        ->and($storedMedia[0]['upload_id'])->toBe($uploadId)
        ->and((int) DB::table('control_response_drafts')->value('revision'))->toBe(1);
});

it('shows a failed Focus draft save instead of claiming success', function () {
    mountControlWorkflow();
    $focus = Livewire::test('forms.page-control-focus');
    Schema::dropIfExists('control_response_drafts');

    $focus->call('setAnswer', 0, 0, 'yes')
        ->assertSet('draftState', 'error')
        ->assertSet('draftError', 'Не удалось сохранить черновик. Повторите сохранение.');
});

it('does not persist client-supplied media paths in Focus', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')
        ->set('answers.0.0.media', [['disk' => 'public', 'path' => 'other-file.jpg']])
        ->call('review')
        ->call('submit')
        ->assertSet('submitted', true);

    $responses = json_decode((string) DB::table('control_responses')->value('responses'), true);
    expect($responses[0][0]['media'])->toBe([]);
});

it('handles a larger Focus room without losing its answers', function () {
    [, , , $control] = mountControlWorkflow();
    $room = controlWorkflowSchema()[0];
    $room['items'] = array_fill(0, 25, $room['items'][0]);
    $control->update(['main' => [$room]]);

    Livewire::test('forms.page-control-focus')
        ->assertCount('questions', 25)
        ->call('start')
        ->call('goToQuestion', 0, 24)
        ->assertSet('currentRoomIndex', 0)
        ->call('setAnswer', 0, 24, 'yes')
        ->call('review', 0)
        ->assertSet('stage', 'review')
        ->assertSet('answers.0.24.selected', 'yes');
});

it('keeps a media upload attached to its original question after navigating away', function () {
    [, , , $control] = mountControlWorkflow();
    $room = controlWorkflowSchema()[0];
    $room['items'][] = ['question' => 'Второй вопрос', 'answer_type' => 'text'];
    $control->update(['main' => [$room, ['title' => 'Кухня', 'items' => [['question' => 'Плита', 'answer_type' => 'text']]]]]);
    Storage::fake('local');

    Livewire::test('forms.page-control-focus')
        ->call('start')
        ->call('beginMediaUpload')
        ->call('goToRoom', 1)
        ->assertSet('currentRoomIndex', 1)
        ->assertSet('pendingMediaCount', 1)
        ->set('mediaUpload', UploadedFile::fake()->image('first.jpg'))
        ->call('attachMedia', 0, 0)
        ->assertCount('savedMedia.0.0', 1)
        ->assertCount('savedMedia.0.1', 0)
        ->assertSet('pendingMediaCount', 0)
        ->call('goToRoom', 0)
        ->assertSet('currentRoomIndex', 0)
        ->assertSee('Фото и видео')
        ->assertSee('Фото')
        ->assertSee('Видео')
        ->assertDontSee('Загрузка файлов');
});

it('shows missing required questions on Focus review and requires explicit confirmation', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    $room = controlWorkflowSchema()[0];
    $room['items'][] = ['question' => 'Дополнительный вопрос', 'answer_type' => 'text'];
    $control->update(['main' => [$room]]);

    Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')
        ->call('submit')
        ->assertSet('submitted', false)
        ->call('start')
        ->call('review', 0)
        ->assertSet('stage', 'review')
        ->assertSee('Дополнительный вопрос')
        ->call('submit')
        ->assertHasErrors('answers.0.1')
        ->call('goToQuestion', 0, 1)
        ->assertSet('stage', 'questions')
        ->set('answers.0.1.custom', 'Ответ')
        ->call('review', 0)
        ->call('submit')
        ->assertSet('submitted', true);

    expect(DB::table('control_responses')->count())->toBe(1);
});

it('blocks Focus submission while media is pending and rejects unsupported uploads', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();
    Storage::fake('public');

    Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')
        ->call('beginMediaUpload')
        ->call('review')
        ->assertSee('Есть незавершённые загрузки')
        ->call('submit')
        ->assertHasErrors('mediaUpload')
        ->assertSet('submitted', false)
        ->call('failMediaUpload')
        ->set('mediaUpload', UploadedFile::fake()->create('document.pdf', 50, 'application/pdf'))
        ->call('attachMedia', 0, 0)
        ->assertHasErrors('mediaUpload')
        ->assertSet('draftState', 'error')
        ->assertCount('savedMedia.0.0', 0);

    expect(DB::table('control_responses')->count())->toBe(0);
});

it('denies Focus to staff who cannot conduct control', function () {
    mountControlWorkflow();
    $cleaner = User::query()->where('role', 'cleaner')->firstOrFail();

    Livewire::actingAs($cleaner)->test('forms.page-control-focus')->assertForbidden();
});

it('denies Classic to staff who cannot conduct control', function () {
    mountControlWorkflow();
    $cleaner = User::query()->where('role', 'cleaner')->firstOrFail();

    Livewire::actingAs($cleaner)->test('forms.page-control')->assertForbidden();
});

it('restores and submits a large Classic draft across six rooms', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    $rooms = [];
    $answers = [];

    for ($roomIndex = 0; $roomIndex < 6; $roomIndex++) {
        $items = [];
        for ($questionIndex = 0; $questionIndex < 14; $questionIndex++) {
            $type = ['options', 'text', 'both'][$questionIndex % 3];
            $items[] = [
                'question' => str_repeat('Проверить состояние помещения и оборудования. ', 8).($questionIndex + 1),
                'answer_type' => $type,
                'answer_options_scored' => $type === 'text' ? [] : [
                    ['value' => 'yes', 'label' => 'Да', 'points' => 1],
                    ['value' => 'no', 'label' => 'Нет', 'points' => 0],
                ],
                'is_optional' => $questionIndex === 13,
            ];
            $answers[$roomIndex][$questionIndex] = [
                'selected' => $type === 'text' ? '' : 'yes',
                'custom' => $type === 'text' ? 'Проверено' : '',
            ];
        }
        $rooms[] = ['title' => 'Комната '.($roomIndex + 1), 'items' => $items, 'is_optional' => $roomIndex === 5];
    }
    $control->update(['main' => $rooms]);

    Livewire::test('forms.page-control')
        ->assertCount('rooms', 6)
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers', $answers)
        ->call('saveDraft')
        ->assertSet('draftState', 'saved');

    Livewire::test('forms.page-control')
        ->assertSet('answers.4.12.selected', 'yes')
        ->assertSet('answers.2.10.custom', 'Проверено')
        ->call('openReview')
        ->assertSet('reviewSheetOpen', true)
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    expect(DB::table('control_responses')->count())->toBe(1)
        ->and(DB::table('control_response_drafts')->count())->toBe(0)
        ->and(json_decode((string) DB::table('control_responses')->value('responses'), true))->toHaveCount(6);
});

it('persists the latest rapid answer edits together and restores them after reload', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    $rooms = [];

    for ($roomIndex = 0; $roomIndex < 2; $roomIndex++) {
        $items = [];
        for ($questionIndex = 0; $questionIndex < 5; $questionIndex++) {
            $items[] = [
                'question' => "Комната {$roomIndex}, вопрос {$questionIndex}?",
                'answer_type' => 'options',
                'answer_options_scored' => [
                    ['value' => 'yes', 'label' => 'Да', 'points' => 1],
                    ['value' => 'no', 'label' => 'Нет', 'points' => 0],
                ],
            ];
        }
        $rooms[] = ['title' => 'Комната '.($roomIndex + 1), 'items' => $items];
    }
    $control->update(['main' => $rooms]);

    $component = Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment);

    foreach (range(0, 4) as $questionIndex) {
        $component->set("answers.0.{$questionIndex}.selected", 'yes');
    }
    foreach (range(0, 4) as $questionIndex) {
        $component->set("answers.1.{$questionIndex}.selected", 'no');
    }

    $component
        ->set('answers.0.0.selected', 'no')
        ->set('answers.0.0.selected', 'yes')
        ->call('saveDraftAuto')
        ->assertReturned('saved')
        ->assertSet('draftState', 'saved');

    $stored = json_decode((string) DB::table('control_response_drafts')->value('responses'), true);

    expect($stored[0][0]['selected'])->toBe('yes')
        ->and($stored[0][4]['selected'])->toBe('yes')
        ->and($stored[1][0]['selected'])->toBe('no')
        ->and($stored[1][4]['selected'])->toBe('no');

    $component
        ->set('answers.0.0.selected', 'no')
        ->call('toggleRoom', 1)
        ->assertReturned('saved')
        ->assertSet('openRoomIndex', 1);

    $stored = json_decode((string) DB::table('control_response_drafts')->value('responses'), true);

    expect($stored[0][0]['selected'])->toBe('no')
        ->and($stored[0][4]['selected'])->toBe('yes')
        ->and($stored[1][0]['selected'])->toBe('no')
        ->and($stored[1][4]['selected'])->toBe('no');

    Livewire::test('forms.page-control')
        ->assertSet('answers.0.0.selected', 'no')
        ->assertSet('answers.0.4.selected', 'yes')
        ->assertSet('answers.1.0.selected', 'no')
        ->assertSet('answers.1.4.selected', 'no');
});

it('restores a legacy Focus draft only when its saved schema snapshot matches', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    $rooms = $control->main;
    $now = now();

    DB::table('control_response_drafts')->insert([
        'control_id' => $control->id,
        'supervisor_id' => auth()->id(),
        'cleaner_id' => $cleaner->id,
        'apartment_id' => $apartment,
        'cleaning_date' => $now->toDateString(),
        'inspection_date' => $now->toDateString(),
        'responses' => json_encode([[['selected' => 'yes', 'custom' => '', 'media' => []]]], JSON_THROW_ON_ERROR),
        'schema_snapshot' => json_encode($rooms, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::test('forms.page-control-focus')
        ->assertSet('answers.0.0.selected', 'yes')
        ->assertSet('cleaner_id', $cleaner->id)
        ->assertSet('apartment_id', $apartment)
        ->call('saveDraft')
        ->assertSet('draftState', 'saved');

    $draft = DB::table('control_response_drafts')->first();
    expect($draft->schema_version)->toBe((new ControlSchema((int) $control->id, $rooms))->version())
        ->and($draft->revision)->toBe(1)
        ->and($draft->run_id)->not->toBeNull();
});

it('preserves an incompatible Focus draft and starts a separate blank run', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    $oldRooms = $control->main;
    $now = now();

    $oldDraftId = DB::table('control_response_drafts')->insertGetId([
        'control_id' => $control->id,
        'supervisor_id' => auth()->id(),
        'cleaner_id' => $cleaner->id,
        'apartment_id' => $apartment,
        'cleaning_date' => $now->toDateString(),
        'inspection_date' => $now->toDateString(),
        'responses' => json_encode([[['selected' => 'yes', 'custom' => 'old answer', 'media' => []]]], JSON_THROW_ON_ERROR),
        'schema_snapshot' => json_encode($oldRooms, JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $control->update(['main' => [[
        'title' => 'Вход',
        'items' => [
            ...$oldRooms[0]['items'],
            [
                'question' => 'Новый вопрос?',
                'answer_type' => 'options',
                'answer_options_scored' => [['value' => 'yes', 'label' => 'Да', 'points' => 1]],
            ],
        ],
    ]]]);

    Livewire::test('forms.page-control-focus')
        ->assertSet('draftSchemaConflict', true)
        ->assertSet('answers.0.0.selected', '')
        ->assertSet('cleaner_id', null)
        ->call('startNewDraft')
        ->assertSet('draftSchemaConflict', false)
        ->assertSet('answers.0.0.selected', '');

    $oldDraft = DB::table('control_response_drafts')->where('id', $oldDraftId)->first();
    $newDraft = DB::table('control_response_drafts')->where('state', 'active')->first();

    expect($oldDraft->state)->toBe('conflict')
        ->and(json_decode($oldDraft->responses, true)[0][0]['selected'])->toBe('yes')
        ->and($newDraft)->not->toBeNull()
        ->and($newDraft->id)->not->toBe($oldDraftId)
        ->and(json_decode($newDraft->responses, true)[0][0]['selected'])->toBe('');
});

it('rejects a stale Focus draft revision without overwriting the latest answers', function () {
    [, , , $control] = mountControlWorkflow();
    $runId = (string) Str::uuid();
    $schemaVersion = (new ControlSchema((int) $control->id, $control->main))->version();
    $now = now();
    $draftId = DB::table('control_response_drafts')->insertGetId([
        'control_id' => $control->id,
        'supervisor_id' => auth()->id(),
        'responses' => json_encode([[['selected' => 'no']]], JSON_THROW_ON_ERROR),
        'schema_snapshot' => json_encode($control->main, JSON_THROW_ON_ERROR),
        'schema_version' => $schemaVersion,
        'run_id' => $runId,
        'revision' => 4,
        'state' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect(fn () => app(ControlRunManager::class)->saveDraft(
        controlId: (int) $control->id,
        supervisorId: (int) auth()->id(),
        runId: $runId,
        expectedRevision: 3,
        draftId: $draftId,
        schemaVersion: $schemaVersion,
        attributes: ['responses' => [['selected' => 'yes']]],
    ))->toThrow(ValidationException::class);

    expect(json_decode((string) DB::table('control_response_drafts')->where('id', $draftId)->value('responses'), true)[0][0]['selected'])
        ->toBe('no');
});

it('makes Focus submission idempotent per run while allowing a new run for the same control', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();
    $manager = app(ControlRunManager::class);
    $schemaVersion = (new ControlSchema((int) $control->id, $control->main))->version();
    $attributes = [
        'cleaner_id' => $cleaner->id,
        'apartment_id' => $apartment,
        'cleaning_date' => now()->toDateString(),
        'inspection_date' => now()->toDateString(),
        'responses' => [],
        'schema_snapshot' => $control->main,
        'total_points' => 0,
        'max_points' => 0,
        'score_percent' => 0,
        'penalty_points' => 0,
        'errors_count' => 0,
        'has_critical_failure' => false,
        'result_zone' => 'green',
        'result_zone_reason' => 'Ошибок нет',
        'status' => ControlResponse::STATUS_SENT,
        'sent_at' => now(),
    ];
    $firstRun = (string) Str::uuid();
    $created = 0;
    $callback = function (ControlResponse $response) use (&$created): void {
        $created++;
    };

    $first = $manager->submitOnce(
        controlId: (int) $control->id,
        supervisorId: (int) auth()->id(),
        runId: $firstRun,
        schemaVersion: $schemaVersion,
        expectedRevision: 0,
        draftId: null,
        attributes: $attributes,
        afterCreate: $callback,
    );
    $retry = $manager->submitOnce(
        controlId: (int) $control->id,
        supervisorId: (int) auth()->id(),
        runId: $firstRun,
        schemaVersion: $schemaVersion,
        expectedRevision: 0,
        draftId: null,
        attributes: $attributes,
        afterCreate: $callback,
    );
    $secondRun = $manager->submitOnce(
        controlId: (int) $control->id,
        supervisorId: (int) auth()->id(),
        runId: (string) Str::uuid(),
        schemaVersion: $schemaVersion,
        expectedRevision: 0,
        draftId: null,
        attributes: $attributes,
        afterCreate: $callback,
    );

    $otherSupervisor = User::create([
        'name' => 'Other Supervisor',
        'role' => 'supervisor',
        'status' => 'approved',
        'is_active' => true,
    ]);

    expect(fn () => $manager->submitOnce(
        controlId: (int) $control->id,
        supervisorId: (int) $otherSupervisor->id,
        runId: $firstRun,
        schemaVersion: $schemaVersion,
        expectedRevision: 0,
        draftId: null,
        attributes: $attributes,
        afterCreate: $callback,
    ))->toThrow(ValidationException::class);

    expect($retry->id)->toBe($first->id)
        ->and($secondRun->id)->not->toBe($first->id)
        ->and($created)->toBe(2)
        ->and(DB::table('control_responses')->count())->toBe(2);
});

it('rejects a client supplied option that is not in the canonical Focus schema', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->set('answers.0.0.selected', 'forged-value')
        ->call('review')
        ->call('submit')
        ->assertSet('submitted', false)
        ->assertHasErrors('answers.0.0');

    expect(DB::table('control_responses')->count())->toBe(0);
});

it('does not restore a conflict draft into Classic Control', function () {
    [, , , $control] = mountControlWorkflow();
    $now = now();
    DB::table('control_response_drafts')->insert([
        'control_id' => $control->id,
        'supervisor_id' => auth()->id(),
        'responses' => json_encode([[['selected' => 'yes']]], JSON_THROW_ON_ERROR),
        'schema_snapshot' => json_encode($control->main, JSON_THROW_ON_ERROR),
        'state' => 'conflict',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    Livewire::test('forms.page-control')
        ->assertSet('answers.0.0.selected', '')
        ->assertSet('draftState', 'idle');
});
it('keeps shared Focus drafts revision-safe when Classic saves them', function () {
    [, $cleaner, $apartment, $control] = mountControlWorkflow();

    $staleFocus = Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes');

    $before = DB::table('control_response_drafts')->first();
    expect((int) $before->revision)->toBeGreaterThan(0);

    Livewire::test('forms.page-control')
        ->set('answers.0.0.selected', 'no')
        ->call('saveDraftAuto')
        ->assertSet('draftState', 'saved');

    $after = DB::table('control_response_drafts')->first();
    $storedAnswer = json_decode((string) $after->responses, true)[0][0];

    expect((int) $after->revision)->toBeGreaterThan((int) $before->revision)
        ->and($after->schema_version)->toBe((new ControlSchema((int) $control->id, $control->main))->version())
        ->and($storedAnswer['question_id'])->toBe((new ControlSchema((int) $control->id, $control->main))->questionIds()[0][0])
        ->and($storedAnswer['selected'])->toBe('no');

    $staleFocus->call('setAnswer', 0, 0, 'yes')->assertSet('draftState', 'error');

    expect(json_decode((string) DB::table('control_response_drafts')->first()->responses, true)[0][0]['selected'])
        ->toBe('no');
});
it('produces the same result for identical Classic and Focus answers', function () {
    [, $cleaner, $apartment] = mountControlWorkflow();

    Livewire::test('forms.page-control')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'no')
        ->call('openReview')
        ->call('confirmSubmit')
        ->assertSet('successSheetOpen', true);

    $classic = DB::table('control_responses')->first();

    Livewire::test('forms.page-control-focus')
        ->set('cleaner_id', $cleaner->id)
        ->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'no')
        ->call('start')
        ->call('review')
        ->call('submit')
        ->assertSet('submitted', true);

    $focus = DB::table('control_responses')->where('id', '<>', $classic->id)->first();
    $scoreFields = [
        'total_points',
        'max_points',
        'score_percent',
        'penalty_points',
        'errors_count',
        'has_critical_failure',
        'result_zone',
        'result_zone_reason',
    ];

    expect(collect($focus)->only($scoreFields)->all())->toBe(collect($classic)->only($scoreFields)->all());
});

it('renders all fifty nine Focus questions without serializing the canonical array into Livewire state', function () {
    [, , , $control] = mountControlWorkflow();
    $rooms = [];
    $counts = [6, 7, 6, 7, 6, 7, 6, 7, 7];

    foreach ($counts as $roomIndex => $count) {
        $items = [];
        for ($questionIndex = 0; $questionIndex < $count; $questionIndex++) {
            $items[] = [
                'question' => "Проверить объект {$roomIndex}-{$questionIndex}",
                'answer_type' => 'options',
                'answer_options_scored' => [
                    ['value' => 'yes', 'label' => 'Да', 'points' => 1],
                    ['value' => 'no', 'label' => 'Нет', 'points' => 0],
                ],
            ];
        }
        $rooms[] = ['title' => "Комната {$roomIndex}", 'items' => $items];
    }

    $control->update(['main' => $rooms]);
    $component = Livewire::test('forms.page-control-focus');
    $snapshot = json_encode($component->snapshot, JSON_THROW_ON_ERROR);
    $data = $component->getData();

    expect($component->get('rooms'))->toHaveCount(9)
        ->and(collect($component->get('rooms'))->sum(fn (array $room): int => count($room['items'])))->toBe(59)
        ->and($data)->not->toHaveKey('rooms')
        ->and($data)->not->toHaveKey('savedMedia')
        ->and(strlen($snapshot))->toBeLessThan(20000);
});
