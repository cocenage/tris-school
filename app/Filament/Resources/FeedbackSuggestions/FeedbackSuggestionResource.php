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
use UnitEnum;

class FeedbackSuggestionResource extends Resource
{
    protected static ?string $model = FeedbackSuggestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Заявки';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'user_name';

    protected static ?string $navigationLabel = 'Обратная связь';

    protected static ?string $modelLabel = 'отзыв / предложение';

    protected static ?string $pluralModelLabel = 'отзывы и предложения';

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