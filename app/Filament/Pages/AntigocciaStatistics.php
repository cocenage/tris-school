<?php

namespace App\Filament\Pages;

use App\Models\AntigocciaReport;
use App\Models\Apartment;
use App\Services\Antigoccia\AntigocciaAnalytics;
use App\Services\Antigoccia\AntigocciaEffectiveness;
use App\Services\Antigoccia\AntigocciaOverview;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\WithPagination;
use UnitEnum;

class AntigocciaStatistics extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Антикапля';

    protected static ?string $title = 'Антикапля · статистика';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.antigoccia-statistics';

    public string $apartmentSearch = '';

    public string $apartmentId = '';

    public string $effectivenessPeriod = '90';

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin'
            && (auth()->user()?->isAdmin() ?? false);
    }

    public function updatedEffectivenessPeriod(): void
    {
        if (! in_array($this->effectivenessPeriod, ['30', '90', '365', '0'], true)) {
            $this->effectivenessPeriod = '90';
        }
    }

    public function getEffectivenessProperty(): array
    {
        return app(AntigocciaEffectiveness::class)->summarize(
            (int) $this->effectivenessPeriod,
            $this->apartment?->id,
        );
    }

    public function updatedApartmentSearch(): void
    {
        $this->resetPage('antigocciaOverview');
    }

    public function getOverviewTotalsProperty(): array
    {
        return app(AntigocciaOverview::class)->totals();
    }

    public function getOverviewRowsProperty()
    {
        $overview = app(AntigocciaOverview::class);
        $apartments = $overview->apartmentQuery($this->apartmentSearch)
            ->paginate(15, ['id', 'name', 'address'], 'antigocciaOverview');
        $apartments->setCollection(collect($overview->rowsForApartments($apartments->getCollection())));

        return $apartments;
    }

    public function selectApartment(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(app(AntigocciaOverview::class)->apartmentQuery()->whereKey($id)->exists(), 404);

        $this->apartmentId = (string) $id;
        $this->apartmentSearch = '';
        $this->resetPage('antigocciaHistory');
    }

    public function clearApartment(): void
    {
        $this->apartmentId = '';
        $this->apartmentSearch = '';
        $this->resetPage('antigocciaHistory');
        $this->resetPage('antigocciaOverview');
    }

    public function getApartmentProperty(): ?Apartment
    {
        if (! ctype_digit($this->apartmentId)) {
            return null;
        }

        return Apartment::query()->find((int) $this->apartmentId);
    }

    public function getStatisticsProperty(): ?array
    {
        return $this->apartment ? app(AntigocciaAnalytics::class)->forApartment($this->apartment) : null;
    }

    public function getHistoryProperty()
    {
        return $this->apartment
            ? AntigocciaReport::query()->where('apartment_id', $this->apartment->id)
                ->orderByDesc('cleaning_date')->orderByDesc('id')->paginate(20, ['*'], 'antigocciaHistory')
            : collect();
    }
}
