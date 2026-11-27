<?php

namespace App\Filament\Resources\Apartments\Pages;

use App\Filament\Resources\Apartments\ApartmentResource;
use App\Models\Apartment;
use App\Models\AntigocciaApplication;
use App\Services\Apartments\ApartmentAccessService;
use App\Services\Apartments\TelegramApartmentImportService;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

class ViewApartment extends ViewRecord
{
    protected static string $resource = ApartmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            Action::make('registerAntigoccia')
                ->label('Антикапля')
                ->icon('heroicon-o-calendar-days')
                ->visible(fn (): bool => $this->canRegisterAntigoccia($this->getRecord()))
                ->modalHeading('Зарегистрировать обработку Антикаплей')
                ->modalDescription(function (Apartment $record): string {
                    $date = AntigocciaApplication::query()->where('apartment_id', $record->id)
                        ->orderByDesc('applied_on')->first()?->applied_on;
                    return $date ? 'Последняя обработка: '.$date->format('d.m.Y') : 'Обработка ещё не зарегистрирована.';
                })
                ->schema([
                    DatePicker::make('applied_on')
                        ->label('Дата обработки')
                        ->default(fn () => now('Europe/Rome')->toDateString())
                        ->maxDate(fn () => now('Europe/Rome')->toDateString())
                        ->required(),
                ])
                ->action(function (Apartment $record, array $data): void {
                    abort_unless($this->canRegisterAntigoccia($record), 403);
                    $validated = Validator::make($data, [
                        'applied_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Rome')->toDateString()],
                    ])->validate();

                    $application = DB::transaction(function () use ($record, $validated): AntigocciaApplication {
                        Apartment::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                        return AntigocciaApplication::query()->firstOrCreate([
                            'apartment_id' => $record->id,
                            'applied_on' => $validated['applied_on'],
                        ], ['created_by' => auth()->id()]);
                    });

                    Notification::make()
                        ->title($application->wasRecentlyCreated ? 'Обработка зарегистрирована' : 'Эта дата уже зарегистрирована')
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
    private function canRegisterAntigoccia(Apartment $record): bool
    {
        $user = auth()->user();

        return $user && $user->isApproved() && $user->is_active
            && in_array($user->role, ['admin', 'supervisor'], true)
            && app(ApartmentAccessService::class)->canView($user, $record);
    }

}
