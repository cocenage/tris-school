<?php

namespace App\Filament\Pages;

use App\Models\AntigocciaReport;
use App\Models\Apartment;
use App\Services\Antigoccia\AntigocciaAnalytics;
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

    public static function canAccess(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'admin'
            && (auth()->user()?->isAdmin() ?? false);
    }

    public function getApartmentMatchesProperty()
    {
        $search = mb_strtolower(trim($this->apartmentSearch));

        return Apartment::query()
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw("LOWER(COALESCE(address, '')) LIKE ?", ['%'.$search.'%']);
            }))
            ->orderBy('name')->limit(30)->get(['id', 'name', 'address']);
    }

    public function selectApartment(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(Apartment::query()->whereKey($id)->exists(), 404);

        $this->apartmentId = (string) $id;
        $this->apartmentSearch = '';
        $this->resetPage('antigocciaHistory');
    }

    public function clearApartment(): void
    {
        $this->apartmentId = '';
        $this->apartmentSearch = '';
        $this->resetPage('antigocciaHistory');
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
