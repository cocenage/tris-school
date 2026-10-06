<?php

use App\Models\Instruction;
use App\Models\User;
use App\Services\UserApplicationBadgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');

    Schema::create('instructions', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug')->unique();
        $table->string('status');
        $table->boolean('is_public');
        $table->unsignedInteger('views_count')->default(0);
        $table->json('blocks')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    $this->instruction = Instruction::create([
        'title' => 'Новый СПИСОК обязательных фото',
        'slug' => 'novyi-spisok-obiazatelnyx-foto',
        'status' => 'published',
        'is_public' => true,
    ]);

    $reader = new User(['status' => 'approved', 'role' => 'cleaner', 'is_active' => true]);
    $reader->id = 1;
    $this->actingAs($reader);
    $this->withoutVite();
});

afterEach(function (): void {
    DB::purge('sqlite');
});

it('redirects the historical photo URL to the existing published instruction', function (): void {
    $destination = route('page-home.instructions.single', $this->instruction->slug);

    $this->get('/instruction/obiazatelnye-foto')
        ->assertStatus(301)
        ->assertRedirect($destination);

    expect(Instruction::query()->count())->toBe(1)
        ->and(Instruction::query()->sole()->slug)->toBe('novyi-spisok-obiazatelnyx-foto');
});

it('redirects a guest to the current instruction URL while keeping the article protected', function (): void {
    Auth::logout();
    $destination = route('page-home.instructions.single', $this->instruction->slug);

    $this->get('/instruction/obiazatelnye-foto')
        ->assertStatus(301)
        ->assertRedirect($destination);

    $this->get($destination)->assertRedirect(route('landing'));
});

it('keeps the current instruction route available', function (): void {
    $this->mock(UserApplicationBadgeService::class)
        ->shouldReceive('label')
        ->andReturnNull();

    $this->get(route('page-home.instructions.single', $this->instruction->slug))
        ->assertOk()
        ->assertSee($this->instruction->title);
});

it('does not redirect an unrelated historical slug to the photo instruction', function (): void {
    $this->get('/instruction/unrelated-instruction')
        ->assertRedirect(route('page-home'));
});

it('does not redirect to an unpublished instruction', function (): void {
    $this->instruction->update(['status' => 'draft']);

    $this->get('/instruction/obiazatelnye-foto')->assertNotFound();
});
