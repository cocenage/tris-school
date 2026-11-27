<?php

namespace App\Filament\Resources\Apartments\Pages;

use App\Filament\Resources\Apartments\ApartmentResource;
use App\Models\AntigocciaApplication;
use App\Models\Apartment;
use App\Services\Apartments\ApartmentAccessService;
use App\Services\Apartments\TelegramApartmentImportService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ViewApartment extends ViewRecord
{
    protected static string $resource = ApartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('antigocciaTreatmentDate')
                ->label(function (Apartment $record): string {
                    $date = AntigocciaApplication::query()
                        ->where('apartment_id', $record->getKey())
                        ->orderByDesc('applied_on')
                        ->orderByDesc('id')
                        ->value('applied_on');

                    return $date
                        ? 'Антикапля · '.CarbonImmutable::parse($date, 'Europe/Rome')->format('d.m.Y')
                        : 'Указать дату обработки';
                })
                ->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => app(ApartmentAccessService::class)->canManage(auth()->user()))
                ->modalHeading('Дата обработки «Антикапля»')
                ->modalSubmitActionLabel('Сохранить дату')
                ->fillForm(fn (Apartment $record): array => [
                    'applied_on' => AntigocciaApplication::query()
                        ->where('apartment_id', $record->getKey())
                        ->orderByDesc('applied_on')
                        ->orderByDesc('id')
                        ->value('applied_on'),
                ])
                ->schema([
                    DatePicker::make('applied_on')
                        ->label('Последняя дата обработки')
                        ->maxDate(now('Europe/Rome')->toDateString())
                        ->required(),
                ])
                ->action(function (Apartment $record, array $data): void {
                    abort_unless(app(ApartmentAccessService::class)->canManage(auth()->user()), 403);

                    AntigocciaApplication::query()->firstOrCreate(
                        [
                            'apartment_id' => $record->getKey(),
                            'applied_on' => $data['applied_on'],
                        ],
                        ['created_by' => auth()->id()],
                    );

                    Notification::make()
                        ->title('Дата обработки сохранена')
                        ->success()
                        ->send();
                }),
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
                            if ($path === null || $path === '' || $path === []) {
                                return 'Загрузите JSON, чтобы увидеть предпросмотр.';
                            }
                            try {
                                $preview = app(TelegramApartmentImportService::class)->preview(
                                    $path,
                                    $get('media_path'),
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
                    Gate::authorize('manageInformation', $record);
                    $result = app(TelegramApartmentImportService::class)->import(
                        $record,
                        $data['json_path'],
                        $data['media_path'] ?? null,
                        auth()->user(),
                    );

                    Notification::make()
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
