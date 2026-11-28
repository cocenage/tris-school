<?php

use App\Filament\Resources\Apartments\ApartmentResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\RecordConnections;
use App\Models\User;
use Filament\Facades\Filament;

it('does not render related data for guests or unapproved users', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $record = new User(['name' => 'Test employee']);

    expect(RecordConnections::render($record)->toHtml())->toBe('');

    $this->actingAs(new User(['role' => 'admin', 'status' => 'pending', 'is_active' => true]));
    expect(RecordConnections::render($record)->toHtml())->toBe('');
});

it('does not offer the employee administration search to supervisors', function () {
    $this->actingAs(new User(['role' => 'supervisor', 'status' => 'approved', 'is_active' => true]));
    Filament::setCurrentPanel(Filament::getPanel('education'));

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(RecordConnections::history(new User())->toHtml())->toContain('Журнал недоступен');
});

it('declares global search attributes for employees and apartments', function () {
    expect(UserResource::getGloballySearchableAttributes())->toContain('name', 'tris_id')
        ->and(ApartmentResource::getGloballySearchableAttributes())->toContain('name', 'code', 'address');
});

it('escapes labels in linked record rows', function () {
    $html = view('filament.shared.record-connections', ['links' => [[
        'label' => '<script>alert(1)</script>',
        'icon' => 'heroicon-o-user',
        'value' => null,
        'url' => '/admin/users',
    ]]])->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;');
});
