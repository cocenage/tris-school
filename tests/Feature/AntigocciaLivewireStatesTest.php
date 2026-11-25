<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();

    foreach (['antigoccia_reports', 'antigoccia_applications', 'apartments'] as $table) {
        Schema::dropIfExists($table);
    }

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
        $table->unsignedInteger('days_since_previous_cleaning');
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
    foreach (['antigoccia_reports', 'antigoccia_applications', 'apartments'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('renders the form and switches from empty through missing photo to complete', function (): void {
    $component = Livewire::test('antigoccia.page-antigoccia')
        ->assertSee('Антикапля')
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
