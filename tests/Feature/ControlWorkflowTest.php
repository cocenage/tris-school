<?php

use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\User;
use App\Services\UserApplicationBadgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    foreach (['activity_log', 'control_responses', 'control_response_drafts', 'apartments', 'controls', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name')->nullable();
        $table->string('role')->default('cleaner');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
        $table->string('telegram_avatar_path')->nullable();
        $table->string('email')->nullable();
        $table->string('password')->nullable();
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
});

afterEach(function () {
    foreach (['activity_log', 'control_responses', 'control_response_drafts', 'apartments', 'controls', 'users'] as $table) {
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

it('renders the loader preview only when explicitly requested', function () {
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
        ->assertDontSee('loader-preview-overlay');
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

it('rejects unsupported Classic attachments', function () {
    mountControlWorkflow();
    Storage::fake('local');

    Livewire::test('forms.page-control')
        ->set('photoUploads.0.0', [UploadedFile::fake()->create('document.pdf', 50, 'application/pdf')])
        ->call('finishPhotoUpload', 0, 0)
        ->assertReturned('error')
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
        ->assertReturned('error')
        ->set('photoUploads.0.0', [UploadedFile::fake()->image('too-big.jpg')->size(11000)])
        ->call('finishPhotoUpload', 0, 0)
        ->assertReturned('error')
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
    $this->actingAs($otherSupervisor)->get($resultUrl)->assertForbidden();
    $this->actingAs($cleaner)->get($resultUrl)->assertOk();
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
        ->set('answers.0.0.corrective.recheck', false);

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
    Storage::fake('public');

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
    Storage::disk('public')->assertExists($media[0]['path']);
    Storage::disk('public')->assertExists($media[1]['path']);

    Livewire::test('forms.page-control-focus')->assertCount('savedMedia.0.0', 2);
    $focus->call('removeMedia', 0, 0, 0)->assertCount('savedMedia.0.0', 1);
    Storage::disk('public')->assertMissing($media[0]['path']);

    $focus->set('cleaner_id', $cleaner->id)->set('apartment_id', $apartment)
        ->call('setAnswer', 0, 0, 'yes')->call('review')->call('submit')->assertSet('submitted', true);
    $saved = json_decode((string) DB::table('control_responses')->value('responses'), true);
    expect($saved[0][0]['media'])->toHaveCount(1)
        ->and($saved[0][0]['media'][0]['mime'])->toBe('video/mp4');
    Storage::disk('public')->assertExists($saved[0][0]['media'][0]['path']);
});

it('shows a failed Focus draft save instead of claiming success', function () {
    mountControlWorkflow();
    $focus = Livewire::test('forms.page-control-focus');
    Schema::dropIfExists('control_response_drafts');

    $focus->call('setAnswer', 0, 0, 'yes')
        ->assertSet('draftState', 'error')
        ->assertSee('Не удалось сохранить черновик');
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
    Storage::fake('public');

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
