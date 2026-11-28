<?php

namespace App\Filament\Resources\ShiftSituations;

use App\Filament\Resources\ShiftSituations\Pages\ManageShiftSituations;
use App\Models\ShiftSituation;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use App\Services\Telegram\TelegramShiftSituationSource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ShiftSituationResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Операционная работа';

    protected static ?string $model = ShiftSituation::class;

    protected static ?string $navigationLabel = 'Ситуации ОКС';

    protected static ?string $modelLabel = 'ситуация ОКС';

    protected static ?string $pluralModelLabel = 'ситуации ОКС';

    public static function canViewAny(): bool
    {
        return auth()->user()?->role === 'admin';
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('date')->label('Дата')->required()->live(),
            Select::make('district_key')->label('Район')->required()->live()
                ->options(fn (): array => app(TelegramDistrictRouteRegistry::class)->sourceRoutes()->pluck('label', 'key')->all())
                ->rules(fn (): array => [Rule::in(app(TelegramDistrictRouteRegistry::class)->sourceRoutes()->pluck('key')->all())]),
            Select::make('event_key')->label('Ситуация из журнала')->required()->searchable()
                ->options(fn ($get): array => app(TelegramShiftSituationSource::class)->events($get('date'), $get('district_key'))->pluck('summary', 'event_key')->all())
                ->rules(fn ($get, ?ShiftSituation $record): array => [
                    Rule::in(app(TelegramShiftSituationSource::class)->events($get('date'), $get('district_key'))->pluck('event_key')->all()),
                    Rule::unique('shift_situations', 'event_key')->where('date', $get('date'))->where('district_key', $get('district_key'))->ignore($record),
                ]),
            Select::make('user_id')->label('Ответственный за ситуацию')->required()->searchable()->preload()
                ->relationship('user', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                    ->where('is_active', true)->where('status', 'approved')
                    ->whereIn('role', ['admin', 'supervisor', 'cleaner']))
                ->rules([Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)->where('status', 'approved')
                    ->whereIn('role', ['admin', 'supervisor', 'cleaner']))]),
            Textarea::make('decision')->label('Принятое решение')->maxLength(5000)
                ->helperText('Заполните после принятия решения. Подтверждение ремонта в Telegram сохраняется отдельно в журнале.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('date')->label('Дата')->date('d.m.Y')->sortable(),
            TextColumn::make('district_key')->label('Район')->searchable(),
            TextColumn::make('event_key')->label('Ситуация')->searchable()->limit(32),
            TextColumn::make('user.name')->label('Ответственный')->searchable(),
            TextColumn::make('decision')->label('Решение')->placeholder('Ожидается')->limit(100)->wrap(),
            TextColumn::make('recorder.name')->label('Записал'),
        ])->defaultSort('date', 'desc')->recordActions([
            EditAction::make()->mutateDataUsing(fn (array $data): array => array_replace($data, ['recorded_by' => auth()->id()])),
            DeleteAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageShiftSituations::route('/')];
    }
}
