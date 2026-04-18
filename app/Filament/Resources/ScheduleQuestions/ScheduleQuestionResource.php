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

class ScheduleQuestionResource extends Resource
{
    protected static ?string $model = ScheduleQuestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'user_name';

    protected static ?string $navigationLabel = 'Вопросы по графику';
    protected static ?string $modelLabel = 'Вопрос по графику';
    protected static ?string $pluralModelLabel = 'Вопросы по графику';
    protected static string|\UnitEnum|null $navigationGroup = 'Формы';

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
}