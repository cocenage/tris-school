<?php

use App\Models\Instruction;
use App\Models\InstructionCategory;
use App\Models\User;
use App\Services\Learning\LearningCatalog;
use App\Services\RolePreviewContext;
use App\Services\UserApplicationBadgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
    });
    (require database_path('migrations/2026_04_27_033156_create_instruction_categories_table.php'))->up();
    (require database_path('migrations/2026_04_27_033240_create_instructions_table.php'))->up();
    (require database_path('migrations/2026_11_15_000100_create_learning_lesson_progress_table.php'))->up();
    DB::table('users')->insert([['id' => 1], ['id' => 2]]);
    $this->reader = new User(['status' => 'approved', 'role' => 'cleaner', 'is_active' => true]);
    $this->reader->id = 1;
    $this->actingAs($this->reader);
    $this->withoutVite();
    $this->mock(UserApplicationBadgeService::class)->shouldReceive('label')->andReturnNull();
    $this->first = Instruction::create([
        'title' => 'Первый материал', 'slug' => 'first', 'status' => 'published', 'is_public' => true,
        'sort_order' => 1, 'blocks' => [['type' => 'text', 'data' => ['content' => '<p>Действующий материал.</p>']]],
    ]);
    $this->second = Instruction::create([
        'title' => 'Второй материал', 'slug' => 'second', 'status' => 'published', 'is_public' => true, 'sort_order' => 2,
    ]);
});

afterEach(function (): void {
    DB::purge('sqlite');
});

it('opens the course and lesson and resumes the last unfinished lesson', function (): void {
    $this->get(route('learning.index'))->assertOk()->assertSee('Опубликованные инструкции')->assertSee('0 из 2');
    $this->get(route('learning.show', 'instructions'))->assertOk()->assertSeeInOrder(['Первый материал', 'Второй материал']);
    $this->get(route('learning.lesson', ['instructions', 'second']))->assertOk()->assertSee('Второй материал');
    $this->get(route('learning.index'))->assertOk()->assertSee('Продолжить обучение')
        ->assertSee(route('learning.lesson', ['instructions', 'second']), false);
    $this->get(route('learning.lesson', ['instructions', 'first']))->assertOk()->assertSee('Действующий материал.');
    expect(DB::table('learning_lesson_progress')->where('user_id', 1)->count())->toBe(2);
});

it('persists completion idempotently and moves to the remaining lesson', function (): void {
    $this->post(route('learning.complete', ['instructions', 'first']))
        ->assertRedirect(route('learning.lesson', ['instructions', 'second']));
    $completedAt = DB::table('learning_lesson_progress')->sole()->completed_at;
    $this->travel(5)->minutes();
    $this->post(route('learning.complete', ['instructions', 'first']))->assertRedirect();
    expect(DB::table('learning_lesson_progress')->sole()->completed_at)->toBe($completedAt);
    $this->get(route('learning.index'))->assertOk()->assertSee('1 из 2')->assertSee('50%');
    $this->post(route('learning.complete', ['instructions', 'second']))->assertRedirect(route('learning.show', 'instructions'));
    $this->get(route('learning.show', 'instructions'))->assertOk()->assertSee('Курс завершён')->assertSee('100%');
});

it('opens a legacy lesson whose instruction has no update date', function (): void {
    DB::table('instructions')->where('id', $this->first->id)->update(['updated_at' => null]);

    $this->get(route('learning.lesson', ['instructions', 'first']))
        ->assertOk()
        ->assertSee('Действующий материал.')
        ->assertDontSee('Обновлено');
});

it('keeps progress private to each user', function (): void {
    $this->post(route('learning.complete', ['instructions', 'first']))->assertRedirect();
    $other = new User(['status' => 'approved', 'role' => 'supervisor', 'is_active' => true]);
    $other->id = 2;
    $this->actingAs($other)->get(route('learning.index'))->assertOk()->assertSee('0 из 2');
    expect(app(LearningCatalog::class)->progress($other, app(LearningCatalog::class)->lessons($other)->get())['records'])->toBeEmpty();
});

it('hides learning progress and blocks completion during role preview', function (): void {
    $this->reader->role = 'admin';
    $this->post(route('learning.complete', ['instructions', 'first']))->assertRedirect();

    $this->withSession([RolePreviewContext::SESSION_KEY => 'cleaner']);
    $this->get(route('learning.index'))->assertOk()->assertDontSee('1 из 2');
    $this->get(route('learning.lesson', ['instructions', 'second']))->assertOk()->assertDontSee('Второй материал');
    $this->post(route('learning.complete', ['instructions', 'second']))->assertForbidden();
    expect(DB::table('learning_lesson_progress')->count())->toBe(1);
});

it('excludes inaccessible materials and rejects direct reads and completion', function (string $state): void {
    match ($state) {
        'draft' => $this->first->update(['status' => 'draft']),
        'private' => $this->first->update(['is_public' => false]),
        'deleted' => $this->first->delete(),
        'inactive-category' => $this->first->update(['instruction_category_id' => InstructionCategory::create(['title' => 'Скрытый', 'slug' => 'hidden', 'is_active' => false])->id]),
    };
    $this->get(route('learning.show', 'instructions'))->assertOk()->assertDontSee('Первый материал');
    $this->get(route('learning.lesson', ['instructions', 'first']))->assertNotFound();
    $this->post(route('learning.complete', ['instructions', 'first']))->assertNotFound();
    expect(DB::table('learning_lesson_progress')->count())->toBe(0);
})->with(['draft', 'private', 'deleted', 'inactive-category']);

it('recalculates progress and resume when a completed lesson is unpublished', function (): void {
    $this->post(route('learning.complete', ['instructions', 'first']))->assertRedirect();
    $this->first->update(['status' => 'draft']);
    $this->get(route('learning.index'))->assertOk()->assertSee('0 из 1')->assertSee(route('learning.lesson', ['instructions', 'second']), false);
});

it('protects learning from guests pending and inactive accounts', function (): void {
    Auth::logout();
    $this->get(route('learning.index'))->assertRedirect(route('landing'));
    $this->reader->status = 'pending';
    $this->actingAs($this->reader)->get(route('learning.index'))->assertRedirect(route('access.pending'));
    $this->reader->status = 'approved';
    $this->reader->is_active = false;
    $this->actingAs($this->reader)->get(route('learning.index'))->assertForbidden();
    $this->get(route('learning.lesson', ['instructions', 'first']))->assertNotFound();
    $this->post(route('learning.complete', ['instructions', 'first']))->assertNotFound();
    expect(DB::table('learning_lesson_progress')->count())->toBe(0);
});

it('shows an empty state and rejects unknown courses', function (): void {
    Instruction::query()->update(['status' => 'draft']);
    $this->get(route('learning.index'))->assertOk()->assertSee('Курсы появятся');
    $this->get(route('learning.show', 'unknown'))->assertNotFound();
});
