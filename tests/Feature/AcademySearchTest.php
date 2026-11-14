<?php

use App\Filament\Pages\AcademySearchReport;
use App\Models\AcademySearchStatistic;
use App\Models\Apartment;
use App\Models\Instruction;
use App\Models\User;
use App\Services\Search\AcademySearch;
use App\Services\Search\AcademySearchStatistics;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    DB::purge('sqlite');
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('role');
        $table->string('status')->default('approved');
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });
    Schema::create('user_panel_accesses', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('panel');
        $table->boolean('can_access')->default(false);
    });
    foreach ([
        '2026_04_27_033156_create_instruction_categories_table.php',
        '2026_04_27_033240_create_instructions_table.php',
        '2026_04_20_185400_create_apartments_table.php',
        '2026_08_02_100000_add_information_fields_to_apartments_table.php',
        '2026_08_02_100400_create_apartment_user_access_table.php',
        '2026_11_15_120000_create_academy_search_statistics_table.php',
    ] as $migration) {
        (require database_path('migrations/'.$migration))->up();
    }
    if (! Route::has('learning.lesson')) {
        Route::get('/learning/{course}/lessons/{lesson}', fn () => 'lesson')->name('learning.lesson');
        Route::getRoutes()->refreshNameLookups();
    }
});

function searchUser(array $attributes = []): User
{
    $user = new User;
    $user->forceFill($attributes + ['name' => 'Search Test', 'role' => 'cleaner', 'status' => 'approved', 'is_active' => true]);
    $user->saveQuietly();

    return $user;
}

function searchInstruction(array $attributes = []): Instruction
{
    return Instruction::create($attributes + [
        'title' => 'Уборка кухни', 'slug' => fake()->unique()->slug(),
        'status' => 'published', 'is_public' => true,
        'short_description' => 'Безопасная уборка',
    ]);
}

it('finds Cyrillic instructions, learning lessons and only explicitly accessible apartments with direct links', function () {
    $user = searchUser();
    $instruction = searchInstruction();
    searchInstruction(['title' => 'Уборка draft', 'status' => 'draft']);
    searchInstruction(['title' => 'Уборка private', 'is_public' => false]);
    $deleted = searchInstruction(['title' => 'Уборка deleted']);
    $deleted->delete();
    $apartment = Apartment::create(['name' => 'Уборка Test', 'code' => 'TEST', 'is_active' => true, 'information_status' => 'published']);
    $hidden = Apartment::create(['name' => 'Уборка скрытая', 'is_active' => true, 'information_status' => 'published']);
    DB::table('apartment_user_access')->insert(['apartment_id' => $apartment->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

    $results = app(AcademySearch::class)->search($user, ' УБОРКА ');
    expect($results)->toHaveCount(3)
        ->and(array_column($results, 'section'))->toBe(['Инструкции', 'Квартиры', 'Обучение'])
        ->and(array_column($results, 'url'))->toContain(route('page-home.instructions.single', $instruction->slug), route('page-apartments.show', $apartment->id), route('learning.lesson', ['course' => 'instructions', 'lesson' => $instruction->slug]))
        ->and(array_column($results, 'title'))->not->toContain($hidden->name);
});

it('rechecks grants expiry, publication and account approval on each search', function () {
    $user = searchUser(['role' => 'supervisor']);
    $apartment = Apartment::create(['name' => 'secret home', 'is_active' => true, 'information_status' => 'published']);
    DB::table('apartment_user_access')->insert(['apartment_id' => $apartment->id, 'user_id' => $user->id, 'expires_at' => now()->subMinute()]);
    $service = app(AcademySearch::class);
    expect($service->search($user, 'secret'))->toBe([]);
    DB::table('apartment_user_access')->update(['expires_at' => null]);
    expect($service->search($user, 'secret'))->toHaveCount(1);
    $apartment->update(['information_status' => 'draft']);
    expect($service->search($user, 'secret'))->toBe([]);
    searchInstruction(['title' => 'secret instruction']);
    $user->status = 'pending';
    expect($service->search($user, 'secret'))->toBe([]);
    $user->status = 'approved';
    $user->is_active = false;
    expect($service->search($user, 'secret'))->toBe([])
        ->and($service->search(null, 'secret'))->toBe([]);
});

it('uses literal matching and caps each result section', function () {
    $user = searchUser();
    foreach (range(1, 12) as $number) {
        searchInstruction(['title' => 'Kitchen '.$number]);
    }
    $service = app(AcademySearch::class);
    expect($service->search($user, 'kitchen'))->toHaveCount(20)
        ->and($service->search($user, '%%'))->toBe([])
        ->and($service->search($user, 'a'))->toBe([])
        ->and($service->search($user, str_repeat('a', 121)))->toBe([]);
});

it('aggregates normalized submitted searches and separately counts empty searches', function () {
    $statistics = app(AcademySearchStatistics::class);
    $statistics->record('  УБОРКА   кухни ', true);
    $statistics->record('уборка кухни', false);
    $statistics->record('  ', false);
    expect(AcademySearchStatistic::count())->toBe(1);
    $row = AcademySearchStatistic::first();
    expect($row->query)->toBe('уборка кухни')
        ->and((int) $row->searches_count)->toBe(2)
        ->and((int) $row->no_results_count)->toBe(1);
});

it('performs search through the existing Livewire entry and records only explicit submissions', function () {
    $user = searchUser();
    $this->actingAs($user);
    searchInstruction(['title' => 'Kitchen advice']);
    $component = Livewire::test('search.search-bar')->set('query', 'Kitchen');
    expect(AcademySearchStatistic::count())->toBe(0);
    $component->call('search')->assertSee('Kitchen advice')->assertSee('Обучение');
    expect((int) AcademySearchStatistic::first()->searches_count)->toBe(1);
    $component->set('opened', true);
    expect((int) AcademySearchStatistic::first()->searches_count)->toBe(1);
    $component->set('query', 'unknown query')->call('search')->assertSee('Ничего не найдено');
    expect((int) AcademySearchStatistic::where('query', 'unknown query')->first()->no_results_count)->toBe(1);
});

it('rejects unauthorized Livewire submissions and restricts report to approved admins', function () {
    $user = searchUser(['status' => 'pending']);
    $this->actingAs($user);
    Livewire::test('search.search-bar')->set('query', 'secret')->call('search')->assertForbidden();
    expect(AcademySearchReport::canAccess())->toBeFalse();
    $user->role = 'supervisor';
    $user->status = 'approved';
    expect(AcademySearchReport::canAccess())->toBeFalse();
    $user->role = 'admin';
    expect(AcademySearchReport::canAccess())->toBeTrue();
});

it('removes revoked apartments from subsequent Livewire renders', function () {
    $user = searchUser();
    $this->actingAs($user);
    $apartment = Apartment::create(['name' => 'Private apartment', 'is_active' => true, 'information_status' => 'published']);
    DB::table('apartment_user_access')->insert(['apartment_id' => $apartment->id, 'user_id' => $user->id]);
    $component = Livewire::test('search.search-bar')->set('query', 'Private')->call('search')->assertSee('Private apartment');
    DB::table('apartment_user_access')->delete();
    $component->set('opened', true)->assertDontSee('Private apartment')->assertSee('Ничего не найдено');
    expect((int) AcademySearchStatistic::first()->searches_count)->toBe(1);
});

it('exposes separately ordered popular and no-result statistics to administrators', function () {
    $this->actingAs(searchUser(['role' => 'admin']));
    $statistics = app(AcademySearchStatistics::class);
    $statistics->record('popular', true);
    $statistics->record('popular', true);
    $statistics->record('missing', false);
    $page = new AcademySearchReport;
    expect($page->getPopularQueriesProperty()->pluck('query')->all())->toBe(['popular', 'missing'])
        ->and($page->getEmptyQueriesProperty()->pluck('query')->all())->toBe(['missing']);
    $this->actingAs(searchUser(['role' => 'supervisor']));
    expect(fn () => $page->getPopularQueriesProperty())->toThrow(HttpException::class);
});
