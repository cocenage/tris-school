<?php

namespace App\Filament\Resources\ShiftDuties;

use App\Filament\Resources\ShiftDuties\Pages\ManageShiftDuties;
use App\Models\ShiftDuty;
use App\Services\Telegram\TelegramDistrictRouteRegistry;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ShiftDutyResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Операционная работа';

    protected static ?string $model = ShiftDuty::class;

    protected static ?string $navigationLabel = 'Дежурные ОКС';

    protected static ?string $modelLabel = 'дежурство';

    protected static ?string $pluralModelLabel = 'дежурства ОКС';

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
            Select::make('district_key')->label('Район')->required()
                ->options(fn (): array => app(TelegramDistrictRouteRegistry::class)->sourceRoutes()->pluck('label', 'key')->all())
                ->rules(fn ($get, ?ShiftDuty $record): array => [
                    Rule::in(app(TelegramDistrictRouteRegistry::class)->sourceRoutes()->pluck('key')->all()),
                    Rule::unique('shift_duties', 'district_key')->where('date', $get('date'))->ignore($record),
                ]),
            Select::make('user_id')->label('Дежурный')->required()->searchable()->preload()
                ->relationship('user', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query
                    ->where('is_active', true)->where('status', 'approved')
                    ->whereIn('role', ['admin', 'supervisor', 'cleaner']))
                ->rules([Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)->where('status', 'approved')
                    ->whereIn('role', ['admin', 'supervisor', 'cleaner']))]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('date')->label('Дата')->date('d.m.Y')->sortable(),
            TextColumn::make('district_key')->label('Район')->searchable(),
            TextColumn::make('user.name')->label('Дежурный')->searchable(),
        ])->defaultSort('date', 'desc')->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageShiftDuties::route('/')];
    }
}
