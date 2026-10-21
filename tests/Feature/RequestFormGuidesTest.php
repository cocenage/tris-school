<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    Schema::dropIfExists('vacation_request_days');
    Schema::create('vacation_request_days', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->date('date');
        $table->string('status');
    });

    Schema::dropIfExists('inventory_items');
    Schema::create('inventory_items', function (Blueprint $table) {
        $table->id();
        $table->json('main');
        $table->boolean('active')->default(true);
        $table->timestamps();
    });
});

it('guides staff through the existing vacation form controls', function () {
    Livewire::test('forms.page-vacation')
        ->assertSee('data-guide-target="vacation-period"', false)
        ->assertSee('data-guide-target="vacation-reason"', false)
        ->assertSee('data-guide-target="vacation-submit"', false)
        ->assertSee('vacation-request-guide-v2');
});

it('guides staff through the existing inventory search, selection, quantity, and submit controls', function () {
    Livewire::test('forms.page-inventory')
        ->assertSee('data-guide-target="inventory-search"', false)
        ->assertSee('data-guide-target="inventory-catalog"', false)
        ->assertSee('data-guide-target="inventory-submit"', false)
        ->assertSee('inventory-request-guide-v2');
});
