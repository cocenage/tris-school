<?php

namespace App\Filament\Resources\KnowledgeEntities;

use App\Filament\Resources\KnowledgeEntities\Pages\CreateKnowledgeEntity;
use App\Filament\Resources\KnowledgeEntities\Pages\EditKnowledgeEntity;
use App\Filament\Resources\KnowledgeEntities\Pages\ListKnowledgeEntities;
use App\Filament\Resources\KnowledgeEntities\RelationManagers\OutgoingRelationsRelationManager;
use App\Models\KnowledgeEntity;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class KnowledgeEntityResource extends Resource
{
    protected static ?string $model = KnowledgeEntity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'TRIS Knowledge';

    protected static ?string $modelLabel = 'статья системы';

    protected static ?string $pluralModelLabel = 'Статьи системы';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Название')->required()->maxLength(255),
            TextInput::make('slug')->label('Адрес статьи')->helperText('Оставьте пустым для генерации из названия.')->maxLength(255)->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')->unique(ignoreRecord: true),
            Select::make('type')->label('Тип')->options(KnowledgeEntity::TYPES)->default('feature')->required(),
            Select::make('status')->label('Статус')->options(KnowledgeEntity::STATUSES)->default('planned')->required(),
            TextInput::make('icon')->label('Символ / emoji')->maxLength(40),
            TextInput::make('group_key')->label('Группа Roadmap')->default('knowledge')->required()->maxLength(80)
                ->helperText('telegram, operations, automation, knowledge — или своя группа.'),
            TextInput::make('sort_order')->label('Порядок')->numeric()->integer()->minValue(0)->default(0)->required(),
            Textarea::make('summary')->label('Краткое описание')->rows(3)->maxLength(2000)->columnSpanFull(),
            MarkdownEditor::make('body')->label('Статья')->fileAttachments(false)->maxLength(100000)->columnSpanFull(),
            Select::make('linked_type')->label('Связанный объект TRIS')->options(KnowledgeEntity::LINKED_TYPES)->live()
                ->afterStateUpdated(fn (callable $set) => $set('linked_id', null)),
            TextInput::make('linked_id')->label('ID объекта')->numeric()->integer()->minValue(1)
                ->helperText('ID существующего объекта выбранного типа. Поля заполняются вместе.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            TextColumn::make('title')->label('Название')->searchable()->sortable(),
            TextColumn::make('type')->label('Тип')->formatStateUsing(fn (string $state): string => KnowledgeEntity::TYPES[$state] ?? $state)->badge(),
            TextColumn::make('status')->label('Статус')->formatStateUsing(fn (string $state): string => KnowledgeEntity::STATUSES[$state] ?? $state)->badge()
                ->color(fn (string $state): string => match ($state) {
                    'active' => 'success', 'in_progress' => 'info', 'planned' => 'warning', default => 'gray',
                }),
            TextColumn::make('group_key')->label('Группа')->searchable()->sortable(),
            TextColumn::make('sort_order')->label('Порядок')->sortable(),
            TextColumn::make('updated_at')->label('Обновлено')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('type')->label('Тип')->options(KnowledgeEntity::TYPES),
            SelectFilter::make('status')->label('Статус')->options(KnowledgeEntity::STATUSES),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getRelations(): array
    {
        return [OutgoingRelationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListKnowledgeEntities::route('/'), 'create' => CreateKnowledgeEntity::route('/create'), 'edit' => EditKnowledgeEntity::route('/{record}/edit')];
    }
}
