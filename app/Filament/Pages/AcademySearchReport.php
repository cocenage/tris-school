<?php

namespace App\Filament\Pages;

use App\Models\AcademySearchStatistic;
use BackedEnum;
use Filament\Pages\Page;

class AcademySearchReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationLabel = 'Запросы поиска';

    protected static ?string $title = 'Поиск по Академии';

    protected string $view = 'filament.pages.academy-search-report';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isApproved() && (bool) auth()->user()?->isAdmin();
    }

    public function getPopularQueriesProperty()
    {
        abort_unless(static::canAccess(), 403);

        return AcademySearchStatistic::query()->orderByDesc('searches_count')->orderBy('query')->limit(50)->get();
    }

    public function getEmptyQueriesProperty()
    {
        abort_unless(static::canAccess(), 403);

        return AcademySearchStatistic::query()->where('no_results_count', '>', 0)->orderByDesc('no_results_count')->orderBy('query')->limit(50)->get();
    }
}
