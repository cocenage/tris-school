<?php

use App\Filament\Pages\ActivityStatisticsReport;
use App\Filament\Pages\TelegramDeliveryReport;
use App\Filament\Support\Dashboard;
use App\Filament\Widgets\WorkspaceOverview;
use App\Models\User;
use App\Services\Authorization\SupervisorPanelAccess;
use Filament\Facades\Filament;

it('keeps the existing dashboard routes and uses the same workspace widget', function () {
    foreach (['admin', 'education'] as $panelId) {
        Filament::setCurrentPanel(Filament::getPanel($panelId));

        expect(Filament::getCurrentPanel()->getPages())->toContain(Dashboard::class)
            ->and((new Dashboard())->getWidgets())->toBe([WorkspaceOverview::class])
            ->and(\Illuminate\Support\Facades\Route::has("filament.{$panelId}.pages.dashboard"))->toBeTrue();
    }
});

it('does not expose dashboard data to guests or unapproved accounts', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(WorkspaceOverview::canView())->toBeFalse();

    $this->actingAs(new User(['role' => 'admin', 'status' => 'pending', 'is_active' => true]));
    expect(WorkspaceOverview::canView())->toBeFalse()
        ->and(TelegramDeliveryReport::canAccess())->toBeFalse();
});

it('allows administration data only on the administration panel', function () {
    $this->actingAs(new User(['role' => 'admin', 'status' => 'approved', 'is_active' => true]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(WorkspaceOverview::canView())->toBeTrue()
        ->and(TelegramDeliveryReport::canAccess())->toBeTrue();

    Filament::setCurrentPanel(Filament::getPanel('education'));
    expect(WorkspaceOverview::canView())->toBeTrue()
        ->and(TelegramDeliveryReport::canAccess())->toBeFalse()
        ->and(ActivityStatisticsReport::canAccess())->toBeFalse();

    Filament::setCurrentPanel(Filament::getPanel('finance'));
    expect(WorkspaceOverview::canView())->toBeFalse();
});

it('respects the supervisor panel grant without granting administration access', function () {
    $user = new User(['role' => 'supervisor', 'status' => 'approved', 'is_active' => true]);
    $this->actingAs($user);
    $this->mock(SupervisorPanelAccess::class, function ($mock) use ($user) {
        $mock->shouldReceive('canUseSupervisorPanel')->with($user)->once()->andReturn(true);
    });

    Filament::setCurrentPanel(Filament::getPanel('education'));
    expect(WorkspaceOverview::canView())->toBeTrue()
        ->and(TelegramDeliveryReport::canAccess())->toBeFalse();

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(WorkspaceOverview::canView())->toBeFalse()
        ->and(TelegramDeliveryReport::canAccess())->toBeFalse();
});
