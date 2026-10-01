<?php

namespace App\Filament\Resources\KnowledgeEntities\RelationManagers;

use App\Models\KnowledgeEntityRelation;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OutgoingRelationsRelationManager extends RelationManager
{
    protected static string $relationship = 'outgoingRelations';

    protected static ?string $title = 'Исходящие связи';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('target_entity_id')->label('Связь с')->relationship('target', 'title')->searchable()->required(),
            Select::make('relation_type')->label('Тип связи')->options(KnowledgeEntityRelation::TYPES)->default('related_to')->required(),
            Textarea::make('note')->label('Примечание')->maxLength(2000),
            TextInput::make('sort_order')->label('Порядок')->numeric()->integer()->minValue(0)->default(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('target.title')->label('Статья')->searchable(),
            TextColumn::make('relation_type')->label('Связь')->formatStateUsing(fn (string $state): string => KnowledgeEntityRelation::TYPES[$state] ?? $state),
            TextColumn::make('note')->label('Примечание')->wrap(),
            TextColumn::make('sort_order')->label('Порядок')->sortable(),
        ])->headerActions([CreateAction::make()])->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
