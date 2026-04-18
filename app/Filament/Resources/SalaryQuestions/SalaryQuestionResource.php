<?php

namespace App\Filament\Resources\SalaryQuestions;

use App\Filament\Resources\SalaryQuestions\Pages\CreateSalaryQuestion;
use App\Filament\Resources\SalaryQuestions\Pages\EditSalaryQuestion;
use App\Filament\Resources\SalaryQuestions\Pages\ListSalaryQuestions;
use App\Filament\Resources\SalaryQuestions\Pages\ViewSalaryQuestion;
use App\Filament\Resources\SalaryQuestions\Schemas\SalaryQuestionForm;
use App\Filament\Resources\SalaryQuestions\Schemas\SalaryQuestionInfolist;
use App\Filament\Resources\SalaryQuestions\Tables\SalaryQuestionsTable;
use App\Models\SalaryQuestion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SalaryQuestionResource extends Resource
{
    protected static ?string $model = SalaryQuestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $recordTitleAttribute = 'user_name';

    protected static ?string $navigationLabel = 'Вопросы по зарплате';
    protected static ?string $modelLabel = 'Вопрос по зарплате';
    protected static ?string $pluralModelLabel = 'Вопросы по зарплате';
    protected static string|\UnitEnum|null $navigationGroup = 'Формы';

    public static function form(Schema $schema): Schema
    {
        return SalaryQuestionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SalaryQuestionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalaryQuestionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalaryQuestions::route('/'),
            'create' => CreateSalaryQuestion::route('/create'),
            'view' => ViewSalaryQuestion::route('/{record}'),
            'edit' => EditSalaryQuestion::route('/{record}/edit'),
        ];
    }
}