<?php

use App\Models\Instruction;
use App\Services\Emergency\EmergencyPublicationTarget;
use App\Services\Emergency\EmergencyScheduleRegistrar;
use App\Services\Emergency\EmergencySitePublisher;
use App\Services\Emergency\EmergencySnapshotBuilder;
use App\Services\Emergency\EmergencyStaticSiteBuilder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    config([
        'app.timezone' => 'Europe/Rome',
        'app.url' => 'https://tris.example.test',
        'app.name' => 'TRIS Academy',
        'database.connections.sqlite.database' => ':memory:',
        'database.default' => 'sqlite',
        'emergency.build_path' => storage_path('framework/testing/emergency-'.Str::uuid()),
        'emergency.publication_disk' => null,
        'emergency.publication_prefix' => '',
        'emergency.feedback_endpoint' => null,
    ]);
    $GLOBALS['emergency_test_path'] = config('emergency.build_path');
    DB::purge('sqlite');

    Schema::connection('sqlite')->create('instruction_categories', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->unsignedInteger('sort_order')->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
    Schema::connection('sqlite')->create('instructions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('instruction_category_id')->nullable();
        $table->unsignedBigInteger('author_id')->nullable();
        $table->string('title');
        $table->string('slug');
        $table->text('short_description')->nullable();
        $table->json('blocks')->nullable();
        $table->string('status')->default('draft');
        $table->boolean('is_public')->default(true);
        $table->boolean('is_emergency_safe')->default(false);
        $table->boolean('is_featured')->default(false);
        $table->unsignedInteger('sort_order')->default(0);
        $table->timestamp('published_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
});

afterEach(function (): void {
    $path = $GLOBALS['emergency_test_path'] ?? null;
    if (is_string($path) && str_contains($path, DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'testing'.DIRECTORY_SEPARATOR.'emergency-')) {
        File::deleteDirectory($path);
    }
    DB::purge('sqlite');
    unset($GLOBALS['emergency_test_path']);
});

function emergencyTestCategory(array $attributes = []): int
{
    return (int) DB::connection('sqlite')->table('instruction_categories')->insertGetId(array_merge([
        'title' => 'Рабочие инструкции',
        'slug' => 'work',
        'sort_order' => 10,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

function emergencyTestInstruction(array $attributes = []): Instruction
{
    return Instruction::query()->create(array_merge([
        'instruction_category_id' => emergencyTestCategory(),
        'title' => 'Безопасная инструкция',
        'slug' => 'safe-instruction-'.Str::uuid(),
        'short_description' => 'Короткое описание.',
        'blocks' => [['type' => 'steps', 'data' => ['title' => 'Порядок действий', 'items' => [['title' => 'Проверьте', 'text' => 'Убедитесь, что зона безопасна.']]]]],
        'status' => 'published',
        'is_public' => true,
        'is_emergency_safe' => true,
        'published_at' => now(),
    ], $attributes));
}

it('builds an explicit safe snapshot with stable instruction groups and no model identifiers', function (): void {
    emergencyTestInstruction(['author_id' => 987654]);
    emergencyTestInstruction(['title' => 'Черновик', 'status' => 'draft', 'slug' => 'draft-only']);
    emergencyTestInstruction(['title' => 'Не опубликована аварийно', 'is_emergency_safe' => false, 'slug' => 'not-emergency-safe']);
    emergencyTestInstruction(['title' => 'Пароль test-secret не публиковать', 'status' => 'draft', 'is_emergency_safe' => false, 'slug' => 'sensitive-draft']);
    $inactiveCategory = emergencyTestCategory(['title' => 'Неактивная', 'slug' => 'inactive', 'is_active' => false]);
    emergencyTestInstruction(['title' => 'Категория отключена', 'instruction_category_id' => $inactiveCategory, 'slug' => 'inactive-category']);

    $snapshot = app(EmergencySnapshotBuilder::class)->build();
    $serialized = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    expect($snapshot)->toHaveKeys(['schema_version', 'snapshot_id', 'generated_at', 'timezone', 'source', 'status', 'instructions', 'feedback', 'freshness'])
        ->and($snapshot['schema_version'])->toBe(1)
        ->and($snapshot['snapshot_id'])->toStartWith('emergency-')
        ->and($snapshot['generated_at'])->not->toBeEmpty()
        ->and($snapshot['instructions'])->toHaveCount(1)
        ->and($snapshot['instructions'][0]['instructions'])->toHaveCount(1)
        ->and($serialized)->not->toContain('987654', 'views_count', 'author_id', 'draft-only', 'not-emergency-safe', 'inactive-category', 'test-secret');
});

it('converts rich instruction content to plain text and blocks likely secrets from emergency export', function (): void {
    emergencyTestInstruction(['blocks' => [['type' => 'text', 'data' => ['title' => 'Порядок', 'content' => '<p>Сохраните инструкцию.</p><script>alert(1)</script><img src=x onerror=alert(2)>']]]]);
    $snapshot = app(EmergencySnapshotBuilder::class)->build();
    $content = $snapshot['instructions'][0]['instructions'][0]['blocks'][0]['content'];

    expect($content)->toContain('Сохраните инструкцию.')
        ->not->toContain('<script', 'onerror=', 'alert(1)', 'alert(2)');

    emergencyTestInstruction(['title' => 'Код доступа 8342', 'slug' => 'sensitive-access-code']);

    expect(fn () => app(EmergencySnapshotBuilder::class)->build())
        ->toThrow(RuntimeException::class, 'content security review');
});

it('generates a self-contained static page and snapshot without a Laravel API dependency', function (): void {
    emergencyTestInstruction();
    $result = app(EmergencyStaticSiteBuilder::class)->build();
    $html = file_get_contents($result['output_path'].DIRECTORY_SEPARATOR.'index.html');
    $snapshotJson = file_get_contents($result['output_path'].DIRECTORY_SEPARATOR.'snapshot.json');

    expect($result['instruction_count'])->toBe(1)
        ->and($html)->toContain('⚠ TRIS — аварийный режим', 'Данные актуальны на:', 'emergency-snapshot', 'Безопасная инструкция')
        ->and($html)->not->toContain('https://tris.example.test', '/api/')
        ->and($snapshotJson)->toContain($result['snapshot']['snapshot_id'], 'Безопасная инструкция')
        ->and(file_exists($result['output_path'].DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'site.js'))->toBeTrue()
        ->and(file_exists($result['output_path'].DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'site.css'))->toBeTrue()
        ->and(json_decode($snapshotJson, true, flags: JSON_THROW_ON_ERROR)['instructions'])->toHaveCount(1);
});

it('uses one configurable feedback form and omits unsafe or primary-origin endpoint URLs', function (): void {
    config(['emergency.feedback_endpoint' => 'https://feedback.example.test/v1/reports']);
    $snapshot = app(EmergencySnapshotBuilder::class)->build();

    expect($snapshot['feedback']['endpoint'])->toBe('https://feedback.example.test/v1/reports')
        ->and(collect($snapshot['feedback']['types'])->pluck('value'))->toContain('problem', 'question', 'change', 'completed', 'help', 'other')
        ->and(collect($snapshot['feedback']['areas'])->pluck('value'))->toContain('cleaning', 'access', 'courier', 'payment', 'apartment', 'system', 'other')
        ->and(collect($snapshot['feedback']['urgencies'])->pluck('value'))->toEqual(['normal', 'important', 'urgent']);

    config(['emergency.feedback_endpoint' => 'https://feedback.example.test/submit?token=secret-value']);
    $unsafeEndpoint = app(EmergencySnapshotBuilder::class)->build();
    config(['emergency.feedback_endpoint' => 'https://tris.example.test/emergency-feedback']);
    $primaryEndpoint = app(EmergencySnapshotBuilder::class)->build();

    expect($unsafeEndpoint['feedback']['endpoint'])->toBeNull()
        ->and(json_encode($unsafeEndpoint, JSON_THROW_ON_ERROR))->not->toContain('secret-value')
        ->and($primaryEndpoint['feedback']['endpoint'])->toBeNull();
});

it('preserves a local editable report draft and exposes a truthful manual fallback without an endpoint', function (): void {
    emergencyTestInstruction();
    $result = app(EmergencyStaticSiteBuilder::class)->build();
    $html = file_get_contents($result['output_path'].DIRECTORY_SEPARATOR.'index.html');
    $javascript = file_get_contents($result['output_path'].DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.'site.js');

    expect($result['snapshot']['feedback']['endpoint'])->toBeNull()
        ->and($html)->toContain('Скопировать отчёт', 'Контакт или ссылка на обращение', 'Сообщение')
        ->and($javascript)->toContain('localStorage', 'не отправлен', 'submission_id', 'snapshot_id', 'navigator.clipboard')
        ->and($javascript)->toContain('fetch(app.feedback.endpoint');
});

it('publishes an immutable release before replacing the static entry point', function (): void {
    emergencyTestInstruction();
    Storage::fake('emergency-test');
    config(['emergency.publication_disk' => 'emergency-test']);
    $build = app(EmergencyStaticSiteBuilder::class)->build();
    $result = app(EmergencySitePublisher::class)->publishLatest();
    $disk = Storage::disk('emergency-test');
    $release = 'releases/'.$build['snapshot']['snapshot_id'];

    expect($result['snapshot_id'])->toBe($build['snapshot']['snapshot_id'])
        ->and($disk->exists($release.'/index.html'))->toBeTrue()
        ->and($disk->exists($release.'/snapshot.json'))->toBeTrue()
        ->and($disk->exists($release.'/assets/site.js'))->toBeTrue()
        ->and($disk->exists($release.'/assets/site.css'))->toBeTrue()
        ->and($disk->exists('index.html'))->toBeTrue()
        ->and($disk->get('index.html'))->toContain($release.'/index.html');
});

it('leaves the previous local and published site intact when a later build or target check fails', function (): void {
    emergencyTestInstruction();
    Storage::fake('emergency-test');
    config(['emergency.publication_disk' => 'emergency-test']);
    $builder = app(EmergencyStaticSiteBuilder::class);
    $first = $builder->build();
    app(EmergencySitePublisher::class)->publishLatest();
    $pointerBefore = file_get_contents(config('emergency.build_path').DIRECTORY_SEPARATOR.'latest.json');
    $remoteIndexBefore = Storage::disk('emergency-test')->get('index.html');

    emergencyTestInstruction(['title' => 'Пароль для входа: unsafe', 'slug' => 'unsafe-later']);
    expect(fn () => $builder->build())->toThrow(RuntimeException::class);
    expect(file_get_contents(config('emergency.build_path').DIRECTORY_SEPARATOR.'latest.json'))->toBe($pointerBefore)
        ->and(file_exists($first['output_path'].DIRECTORY_SEPARATOR.'index.html'))->toBeTrue();

    config(['emergency.publication_disk' => 'missing-disk']);
    expect(fn () => app(EmergencySitePublisher::class)->publishLatest())->toThrow(RuntimeException::class)
        ->and(Storage::disk('emergency-test')->get('index.html'))->toBe($remoteIndexBefore);
});

it('schedules emergency publishing every five minutes with overlap protection only for a configured disk', function (): void {
    $schedule = app(Schedule::class);
    $registrar = app(EmergencyScheduleRegistrar::class);
    $registrar->register($schedule);

    expect(collect($schedule->events())->contains(fn ($event): bool => str_contains($event->command ?? '', 'emergency:publish')))->toBeFalse();

    Storage::fake('emergency-test');
    config(['emergency.publication_disk' => 'emergency-test']);
    $registrar->register($schedule);
    $event = collect($schedule->events())->first(fn ($event): bool => str_contains($event->command ?? '', 'emergency:publish'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('keeps the existing lightweight Laravel health endpoint available', function (): void {
    $this->get('/up')->assertOk();
});
