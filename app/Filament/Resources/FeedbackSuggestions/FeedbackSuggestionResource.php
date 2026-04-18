<?php

namespace App\Filament\Resources\FeedbackSuggestions;

use App\Filament\Resources\FeedbackSuggestions\Pages\CreateFeedbackSuggestion;
use App\Filament\Resources\FeedbackSuggestions\Pages\EditFeedbackSuggestion;
use App\Filament\Resources\FeedbackSuggestions\Pages\ListFeedbackSuggestions;
use App\Filament\Resources\FeedbackSuggestions\Pages\ViewFeedbackSuggestion;
use App\Filament\Resources\FeedbackSuggestions\Schemas\FeedbackSuggestionForm;
use App\Filament\Resources\FeedbackSuggestions\Schemas\FeedbackSuggestionInfolist;
use App\Filament\Resources\FeedbackSuggestions\Tables\FeedbackSuggestionsTable;
use App\Models\FeedbackSuggestion;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FeedbackSuggestionResource extends Resource
{
    protected static ?string $model = FeedbackSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?string $recordTitleAttribute = 'user_name';

    protected static ?string $navigationLabel = 'Отзывы и предложения';
    protected static ?string $modelLabel = 'Отзыв / предложение';
    protected static ?string $pluralModelLabel = 'Отзывы и предложения';
    protected static string|\UnitEnum|null $navigationGroup = 'Формы';

    public static function form(Schema $schema): Schema
    {
        return FeedbackSuggestionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FeedbackSuggestionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeedbackSuggestionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeedbackSuggestions::route('/'),
            'create' => CreateFeedbackSuggestion::route('/create'),
            'view' => ViewFeedbackSuggestion::route('/{record}'),
            'edit' => EditFeedbackSuggestion::route('/{record}/edit'),
        ];
    }
}