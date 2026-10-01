<?php

use App\Filament\Resources\KnowledgeEntities\KnowledgeEntityResource;
use App\Filament\Resources\KnowledgeEntities\Pages\ListKnowledgeEntities;
use App\Models\Instruction;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEntityRelation;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Schemas\Schema as FilamentSchema;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
    DB::purge('sqlite');
    $migration = require base_path('database/migrations/2026_10_01_000000_create_knowledge_library_tables.php');
    $migration->up();
    Http::preventStrayRequests();
    $this->withoutVite();
    $this->reader = new User(['name' => 'Reader', 'status' => 'approved', 'role' => 'cleaner', 'is_active' => true]);
    $this->reader->id = 1;
});

afterEach(function (): void {
    DB::purge('sqlite');
});

it('creates entities with stable unique slugs and binds articles by slug', function (): void {
    $entity = KnowledgeEntity::create(['title' => 'Evening Intelligence']);
    $duplicateTitle = KnowledgeEntity::create(['title' => 'Evening Intelligence']);
    expect($entity->slug)->toBe('evening-intelligence')
        ->and($duplicateTitle->slug)->toBe('evening-intelligence-2')
        ->and($entity->getRouteKeyName())->toBe('slug');
    $entity->update(['title' => 'Evening report']);
    expect($entity->slug)->toBe('evening-intelligence');
    $this->actingAs($this->reader)->get(route('knowledge.show', $entity))->assertOk()->assertSee('Evening report');
    $this->get('/knowledge/entities/'.$entity->id)->assertNotFound();
});

it('requires authenticated approved access', function (): void {
    $entity = KnowledgeEntity::create(['title' => 'Protected article']);
    $this->get('/knowledge')->assertRedirect(route('landing'));
    $this->get('/knowledge/entities')->assertRedirect(route('landing'));
    $this->get(route('knowledge.show', $entity))->assertRedirect(route('landing'));
    $pending = new User(['status' => 'pending']);
    $pending->id = 2;
    $this->actingAs($pending)->get('/knowledge')->assertRedirect(route('access.pending'));
    $this->actingAs($this->reader)->get('/knowledge')->assertOk();
});

it('renders grouped roadmap cards with type status and summary', function (): void {
    KnowledgeEntity::create(['title' => 'AcademyBot', 'type' => 'bot', 'status' => 'active', 'group_key' => 'telegram', 'summary' => 'Main bot documentation']);
    KnowledgeEntity::create(['title' => 'Future Controls', 'status' => 'planned', 'group_key' => 'automation']);
    $this->actingAs($this->reader)->get('/knowledge')->assertOk()
        ->assertSee('AcademyBot')->assertSee('Main bot documentation')->assertSee('Активно')
        ->assertSee('Telegram')->assertSee('Автоматизация')->assertSee('Запланировано');
});

it('renders a readable article and safely sanitizes Markdown', function (): void {
    $entity = KnowledgeEntity::create([
        'title' => 'Operational Intelligence', 'summary' => 'A readable system overview.',
        'body' => "## Что это\n\nОписание **системы**.\n\n- Один\n- Два\n\n> Важный принцип\n\n`inline`\n\n```php\necho 'hello';\n```\n\n[Safe](https://example.com)\n\n<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n[Unsafe](javascript:alert(1))",
    ]);
    $html = $entity->renderedBody();
    expect($html)->toContain('<h2>', '<strong>', '<ul>', '<blockquote>', '<pre>', '<code', 'https://example.com')
        ->not->toContain('<script', '<img', 'onerror=', 'href="javascript:');
    $this->actingAs($this->reader)->get(route('knowledge.show', $entity))->assertOk()
        ->assertSee('Operational Intelligence')->assertSee('A readable system overview.')
        ->assertSee('Что это')->assertSee('<h2>', false)->assertDontSee('<script>alert(1)', false);
});

it('stores one directed relation and renders both directions with linked articles', function (): void {
    $source = KnowledgeEntity::create(['title' => 'AcademyBot']);
    $target = KnowledgeEntity::create(['title' => 'Event Ledger']);
    $relation = $source->outgoingRelations()->create(['target_entity_id' => $target->id, 'relation_type' => 'produces', 'note' => 'Message evidence']);
    expect($source->outgoingRelations()->sole()->target->id)->toBe($target->id)
        ->and($target->incomingRelations()->sole()->source->id)->toBe($source->id);
    $this->actingAs($this->reader)->get(route('knowledge.show', $source))->assertSee('Event Ledger')->assertSee(route('knowledge.show', $target), false)->assertSee('Message evidence');
    $this->get(route('knowledge.show', $target))->assertSee('AcademyBot')->assertSee(route('knowledge.show', $source), false);
    expect(fn () => KnowledgeEntityRelation::create($relation->only(['source_entity_id', 'target_entity_id', 'relation_type'])))->toThrow(ValidationException::class);
    expect(fn () => DB::table('knowledge_entity_relations')->insert($relation->only(['source_entity_id', 'target_entity_id', 'relation_type'])))->toThrow(QueryException::class);
});

it('searches title summary and body with status and type filters', function (string $field): void {
    $match = KnowledgeEntity::create(['title' => 'Matched entity', $field => 'needle', 'status' => 'active', 'type' => 'system']);
    KnowledgeEntity::create(['title' => 'Unrelated entity']);
    $this->actingAs($this->reader)->get('/knowledge/entities?q=needle&type=system&status=active')
        ->assertOk()->assertSee($match->title)->assertDontSee('Unrelated entity')
        ->assertViewHas('entities', fn ($entities): bool => $entities->pluck('id')->all() === [$match->id]);
    $this->get('/knowledge/entities?q=needle&status=planned')->assertOk()
        ->assertViewHas('entities', fn ($entities): bool => $entities->isEmpty());
})->with(['title', 'summary', 'body']);

it('paginates the catalog and preserves filters', function (): void {
    foreach (range(1, 19) as $number) {
        KnowledgeEntity::create(['title' => 'Item '.$number, 'summary' => 'searchable', 'sort_order' => $number]);
    }
    $this->actingAs($this->reader)->get('/knowledge/entities?q=searchable')->assertOk()
        ->assertViewHas('entities', fn ($entities): bool => $entities->count() === 18 && $entities->total() === 19);
    $this->get('/knowledge/entities?q=searchable&page=2')->assertOk()->assertSee('Item 19');
});

it('links to existing instructions without duplicating content or exposing unpublished instructions', function (): void {
    Schema::create('instructions', function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->string('slug');
        $table->string('status');
        $table->boolean('is_public');
        $table->softDeletes();
        $table->timestamps();
    });
    $instruction = Instruction::create(['title' => 'Photos instruction', 'slug' => 'photos', 'status' => 'published', 'is_public' => true]);
    $entity = KnowledgeEntity::create(['title' => 'Photo documentation', 'linked_type' => Instruction::class, 'linked_id' => $instruction->id]);
    expect($entity->linked)->toBeInstanceOf(Instruction::class)->and($entity->linked->id)->toBe($instruction->id);
    $this->actingAs($this->reader)->get(route('knowledge.show', $entity))->assertSee('Photos instruction')->assertSee(route('page-home.instructions.single', 'photos'), false);
    $instruction->update(['status' => 'draft']);
    $this->get(route('knowledge.show', $entity))->assertDontSee('Photos instruction');
    expect(fn () => KnowledgeEntity::create(['title' => 'Missing link', 'linked_type' => Instruction::class, 'linked_id' => 999]))->toThrow(ValidationException::class);
    expect(fn () => KnowledgeEntity::create(['title' => 'Partial link', 'linked_type' => Instruction::class]))->toThrow(ValidationException::class);
    expect(fn () => KnowledgeEntity::create(['title' => 'Invalid link', 'linked_type' => User::class, 'linked_id' => 1]))->toThrow(ValidationException::class);
});

it('restricts admin management and loads the Filament resource', function (): void {
    $entity = KnowledgeEntity::create(['title' => 'Managed entity']);
    $this->actingAs($this->reader);
    expect(Gate::allows('create', KnowledgeEntity::class))->toBeFalse()
        ->and(Gate::allows('update', $entity))->toBeFalse()
        ->and(Gate::allows('create', KnowledgeEntityRelation::class))->toBeFalse();
    $admin = new User(['name' => 'Owner', 'role' => 'admin', 'status' => 'approved', 'is_active' => true]);
    $admin->id = 3;
    $this->actingAs($admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(Gate::allows('create', KnowledgeEntity::class))->toBeTrue()
        ->and(KnowledgeEntityResource::form(FilamentSchema::make())->getComponents())->not->toBeEmpty();
    Livewire::test(ListKnowledgeEntities::class)->assertSuccessful()->assertCanSeeTableRecords([$entity]);
});
