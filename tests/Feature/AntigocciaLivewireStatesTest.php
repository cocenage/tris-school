<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();

    foreach (['antigoccia_reports', 'antigoccia_applications', 'apartments', 'control_responses'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('control_responses', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('cleaning_date')->nullable();
        $table->string('status');
    });

    Schema::create('apartments', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('address')->nullable();
        $table->boolean('is_active')->default(true);
        $table->string('information_status')->default('published');
        $table->timestamps();
    });

    Schema::create('antigoccia_applications', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->date('applied_on');
        $table->unsignedBigInteger('created_by')->nullable();
        $table->timestamps();
    });

    Schema::create('antigoccia_reports', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('apartment_id');
        $table->unsignedBigInteger('user_id');
        $table->string('cleaner_name');
        $table->date('cleaning_date');
        $table->unsignedInteger('days_since_previous_cleaning')->nullable();
        $table->json('answers');
        $table->json('photos')->nullable();
        $table->boolean('remaining')->default(false);
        $table->uuid('submission_uuid');
        $table->timestamps();
    });

    $actor = new User([
        'name' => 'Администратор',
        'role' => 'admin',
        'status' => 'approved',
        'is_active' => true,
    ]);
    $actor->id = 51;
    Livewire::actingAs($actor);
});

afterEach(function (): void {
    foreach (['antigoccia_reports', 'antigoccia_applications', 'apartments', 'control_responses'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('renders the form and switches from empty through missing photo to complete', function (): void {
    $component = Livewire::test('antigoccia.page-antigoccia')
        ->assertSee('Данные уборки')
        ->assertDontSee('Дней с прошлой уборки')
        ->assertSee('Не начато')
        ->assertSee('Проверка поверхностей');

    $component
        ->call('setDirt', 'mirrors', 2)
        ->call('setRemoval', 'mirrors', 'hard')
        ->set('answers.mirrors.remaining', 'yes')
        ->assertSee('Нужно фото');

    expect($component->instance()->completion['completed'])->toBe(0)
        ->and($component->instance()->completion['items']['mirrors']['needs_photo'])->toBeTrue();

    $component->set('answers.mirrors.remaining', 'no')->assertSee('Заполнено');
    expect($component->instance()->completion['completed'])->toBe(1);
});

it('selects an apartment without losing previously established surface logic', function (): void {
    $id = \Illuminate\Support\Facades\DB::table('apartments')->insertGetId([
        'name' => 'Тестовая квартира', 'address' => 'Via Esempio 1', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $component = Livewire::test('antigoccia.page-antigoccia')
        ->call('selectApartment', $id)
        ->assertSee('Тестовая квартира')
        ->assertSee('Выбрана')
        ->call('setDirt', 'mirrors', 0)
        ->call('setRemoval', 'mirrors', 'no_dirt')
        ->set('answers.mirrors.remaining', 'no');

    expect($component->instance()->completion['completed'])->toBe(1);
    $component->call('clearApartment')->assertSee('Не начато');
    expect($component->instance()->completion['completed'])->toBe(0);
});

it('dispatches the first invalid field on submit instead of losing the scroll position', function (): void {
    $component = Livewire::test('antigoccia.page-antigoccia');
    $component->call('submit')->assertHasErrors(['apartmentId'])
        ->assertDispatched('antigoccia-invalid', field: 'apartmentId');

    $id = \Illuminate\Support\Facades\DB::table('apartments')->insertGetId([
        'name' => 'Тестовая квартира', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $component->call('selectApartment', $id)
        ->call('submit')->assertHasErrors(['answers.mirrors.dirt'])
        ->assertDispatched('antigoccia-invalid', field: 'answers.mirrors.dirt');
});

it('lets approved cleaners select active apartments without individual grants', function (): void {
    $actor = new User([
        'name' => 'Клинер',
        'role' => 'cleaner',
        'status' => 'approved',
        'is_active' => true,
    ]);
    $actor->id = 52;
    Livewire::actingAs($actor);

    $id = \Illuminate\Support\Facades\DB::table('apartments')->insertGetId([
        'name' => 'Квартира без доступа',
        'information_status' => 'draft',
        'is_active' => true,
    ]);
    $inactive = \Illuminate\Support\Facades\DB::table('apartments')->insertGetId([
        'name' => 'Архивная квартира',
        'is_active' => false,
    ]);
    $component = Livewire::test('antigoccia.page-antigoccia')
        ->assertSee('Квартира без доступа')
        ->assertDontSee('Архивная квартира')
        ->call('selectApartment', $id)
        ->assertSet('apartmentId', (string) $id);

    $component->call('selectApartment', $inactive)->assertForbidden();
});

it('submits without a manual cleaning interval and calculates only recorded previous dates', function (): void {
    $id = \Illuminate\Support\Facades\DB::table('apartments')->insertGetId([
        'name' => 'Тестовая квартира',
    ]);
    $date = now('Europe/Rome')->startOfDay();
    \Illuminate\Support\Facades\DB::table('control_responses')->insert([
        'apartment_id' => $id,
        'cleaning_date' => $date->copy()->subDays(3)->toDateString(),
        'status' => 'sent',
    ]);

    $component = Livewire::test('antigoccia.page-antigoccia')
        ->call('selectApartment', $id);
    foreach (\App\Services\Antigoccia\AntigocciaAssessment::SURFACES as $surface => $label) {
        $component->call('setDirt', $surface, 0)
            ->call('setRemoval', $surface, 'no_dirt')
            ->set('answers.'.$surface.'.remaining', 'no');
    }
    $component->call('submit')->assertHasNoErrors()->assertSet('saved', true);
    expect(\App\Models\AntigocciaReport::query()->sole()->days_since_previous_cleaning)->toBe(3);
});
