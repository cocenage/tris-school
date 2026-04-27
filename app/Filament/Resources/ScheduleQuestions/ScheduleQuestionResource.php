<?php

namespace App\Filament\Resources\ScheduleQuestions;

use App\Filament\Resources\ScheduleQuestions\Pages\CreateScheduleQuestion;
use App\Filament\Resources\ScheduleQuestions\Pages\EditScheduleQuestion;
use App\Filament\Resources\ScheduleQuestions\Pages\ListScheduleQuestions;
use App\Filament\Resources\ScheduleQuestions\Pages\ViewScheduleQuestion;
use App\Filament\Resources\ScheduleQuestions\Schemas\ScheduleQuestionForm;
use App\Filament\Resources\ScheduleQuestions\Schemas\ScheduleQuestionInfolist;
use App\Filament\Resources\ScheduleQuestions\Tables\ScheduleQuestionsTable;
use App\Models\ScheduleQuestion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ScheduleQuestionResource extends Resource
{
    protected static ?string $model = ScheduleQuestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Заявки';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'user_name';

    protected static ?string $navigationLabel = 'Вопросы по графику';

    protected static ?string $modelLabel = 'вопрос по графику';

    protected static ?string $pluralModelLabel = 'вопросы по графику';

    public static function form(Schema $schema): Schema
    {
        return ScheduleQuestionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ScheduleQuestionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ScheduleQuestionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScheduleQuestions::route('/'),
            'create' => CreateScheduleQuestion::route('/create'),
            'view' => ViewScheduleQuestion::route('/{record}'),
            'edit' => EditScheduleQuestion::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $pendingCount = static::getModel()::query()
            ->where('status', 'pending')
            ->count();

        return $pendingCount > 0 ? (string) $pendingCount : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}