<?php

namespace App\Filament\Resources\Apartments\RelationManagers;

use App\Models\ApartmentInformationAttachment;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class InformationAttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'informationAttachments';

    protected static ?string $title = 'Фотографии и документы';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('information_section_id')
                ->label('Раздел')
                ->relationship('section', 'title')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('apartment_id', $this->getOwnerRecord()->getKey()))
                ->searchable()
                ->preload()
                ->nullable(),

            FileUpload::make('path')
                ->label('Файл')
                ->disk('local')
                ->directory(fn (): string => 'apartment-information/' . $this->getOwnerRecord()->getKey())
                ->acceptedFileTypes(ApartmentInformationAttachment::ALLOWED_MIME_TYPES)
                ->maxSize(10240)
                ->storeFileNamesIn('original_name')
                ->required(fn (string $operation): bool => $operation === 'create'),

            TextInput::make('caption')
                ->label('Подпись')
                ->maxLength(255),

            TextInput::make('sort_order')
                ->label('Порядок')
                ->numeric()
                ->minValue(0)
                ->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('original_name')
                    ->label('Файл')
                    ->searchable()
                    ->wrap()
                    ->weight('medium'),

                TextColumn::make('section.title')
                    ->label('Раздел')
                    ->placeholder('Общие файлы'),

                TextColumn::make('mime_type')
                    ->label('Тип'),

                TextColumn::make('file_size')
                    ->label('Размер')
                    ->formatStateUsing(fn (?int $state): string => $state ? number_format($state / 1024, 0, ',', ' ') . ' КБ' : '—'),

                TextColumn::make('uploadedBy.name')
                    ->label('Загрузил')
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Загружено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Загрузить файл')
                    ->mutateDataUsing(function (array $data): array {
                        $data['disk'] = 'local';
                        $data['uploaded_by'] = auth()->id();

                        if (filled($data['path'] ?? null)) {
                            $disk = Storage::disk('local');
                            $data['mime_type'] = $disk->mimeType($data['path']);
                            $data['file_size'] = $disk->size($data['path']);
                        }

                        return $data;
                    }),
            ])
            ->actions([
                EditAction::make()->label('Изменить'),
                DeleteAction::make()->label('Удалить')->requiresConfirmation(),
            ]);
    }
}
