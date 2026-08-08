<?php

namespace App\Filament\Resources\Apartments\Pages;

use App\Filament\Resources\Apartments\ApartmentResource;
use App\Services\Apartments\ApartmentAccessService;
use App\Services\Apartments\TelegramApartmentImportService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Get;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ViewApartment extends ViewRecord
{
    protected static string $resource = ApartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('importTelegram')
                ->label('Импорт из Telegram')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn (): bool => app(ApartmentAccessService::class)->canManage(auth()->user()))
                ->modalHeading('Импорт из Telegram Desktop')
                ->modalSubmitActionLabel('Импортировать как черновик')
                ->schema([
                    FileUpload::make('json_path')
                        ->label('JSON экспорт Telegram Desktop')
                        ->disk('local')
                        ->directory('telegram-imports')
                        ->acceptedFileTypes(['application/json', 'text/json'])
                        ->maxSize(51200)
                        ->required()
                        ->live(),
                    FileUpload::make('media_path')
                        ->label('Архив media (необязательно)')
                        ->disk('local')
                        ->directory('telegram-imports')
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->maxSize(204800),
                    Placeholder::make('preview')
                        ->label('Предпросмотр')
                        ->content(function (Get $get) {
                            $path = $get('json_path');
                            if (! is_string($path) || $path === '') {
                                return 'Загрузите JSON, чтобы увидеть предпросмотр.';
                            }
                            try {
                                $preview = app(TelegramApartmentImportService::class)->preview(
                                    storage_path('app/'.$path),
                                    is_string($get('media_path')) ? storage_path('app/'.$get('media_path')) : null,
                                );
                            } catch (ValidationException $exception) {
                                return new HtmlString('<span class="text-danger-600">'.e($exception->getMessage()).'</span>');
                            }
                            return view('filament.components.telegram-import-preview', compact('preview'));
                        }),
                    Checkbox::make('confirm')
                        ->label('Добавить материалы как черновики (не публиковать автоматически)')
                        ->accepted()
                        ->required(),
                ])
                ->action(function (Apartment $record, array $data): void {
                    $result = app(TelegramApartmentImportService::class)->import(
                        $record,
                        storage_path('app/'.$data['json_path']),
                        isset($data['media_path']) ? storage_path('app/'.$data['media_path']) : null,
                        auth()->user(),
                    );

                    \Filament\Notifications\Notification::make()
                        ->title('Импорт завершён')
                        ->body(sprintf(
                            'Сообщений: %d, изображений: %d, документов: %d, пропущено: %d.',
                            $result['message_count'], $result['photo_count'], $result['document_count'], $result['skipped_count'],
                        ))
                        ->success()
                        ->send();
                }),
        ];
    }
}
