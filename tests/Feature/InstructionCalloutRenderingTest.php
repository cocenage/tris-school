<?php

use App\Models\Instruction;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
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

    $this->actingAs(new User(['status' => 'approved', 'role' => 'cleaner', 'is_active' => true]));
    $this->withoutVite();
});
afterEach(function (): void {
    DB::purge('sqlite');
});

it('renders each typed instruction callout with its semantic style and icon while keeping regular quotes neutral', function (): void {
    Instruction::create([
        'title' => 'Callout styles',
        'slug' => 'callout-styles',
        'status' => 'published',
        'is_public' => true,
        'blocks' => [
            ['type' => 'warning', 'data' => ['type' => 'info', 'title' => 'Information title', 'content' => "Information body\nwith a second line."]],
            ['type' => 'warning', 'data' => ['type' => 'warning', 'title' => 'Warning title', 'content' => 'Warning body']],
            ['type' => 'warning', 'data' => ['type' => 'danger', 'title' => 'Critical title', 'content' => 'Critical body']],
            ['type' => 'warning', 'data' => ['type' => 'success', 'title' => 'Success title', 'content' => 'Success body']],
            ['type' => 'text', 'data' => ['title' => 'Regular quote', 'content' => '<blockquote><p>Neutral quotation.</p></blockquote>']],
        ],
    ]);

    $response = $this->get(route('page-home.instructions.single', 'callout-styles'));

    $response->assertOk()
        ->assertSee('data-callout-type="info"', false)
        ->assertSee('bg-[#EFF6FF]', false)
        ->assertSee('dark:bg-[#172554]', false)
        ->assertSee('data-callout-icon="info"', false)
        ->assertSee('text-[#2563EB]', false)
        ->assertSee('data-callout-type="warning"', false)
        ->assertSee('bg-[#FFFBEB]', false)
        ->assertSee('dark:bg-[#422006]', false)
        ->assertSee('data-callout-icon="alert-triangle"', false)
        ->assertSee('text-[#D97706]', false)
        ->assertSee('data-callout-type="danger"', false)
        ->assertSee('bg-[#FEF2F2]', false)
        ->assertSee('dark:bg-[#450A0A]', false)
        ->assertSee('data-callout-icon="alert-octagon"', false)
        ->assertSee('text-[#DC2626]', false)
        ->assertSee('data-callout-type="success"', false)
        ->assertSee('bg-[#F0FDF4]', false)
        ->assertSee('dark:bg-[#052E16]', false)
        ->assertSee('data-callout-icon="check-circle"', false)
        ->assertSee('text-[#16A34A]', false)
        ->assertSee("Information body\nwith a second line.", false)
        ->assertSee('Neutral quotation.', false)
        ->assertSee('<blockquote><p>Neutral quotation.</p></blockquote>', false);
});

it('renders the instruction editor preview through the same callout component', function (): void {
    $preview = view('filament.instructions.callout-preview', [
        'type' => 'success',
        'title' => 'Готово',
        'content' => 'Проверка сохранена.',
    ])->render();

    expect($preview)
        ->toContain('data-callout-type="success"')
        ->toContain('bg-[#F0FDF4]')
        ->toContain('data-callout-icon="check-circle"')
        ->toContain('text-[#16A34A]')
        ->toContain('Проверка сохранена.');
});
