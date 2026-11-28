<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Задачи';
    protected static ?string $modelLabel = 'задача';
    protected static ?string $pluralModelLabel = 'Задачи';

    private static function allowed(): bool
    {
        $user = auth()->user();

        return Filament::getCurrentPanel()?->getId() === 'admin'
            && $user instanceof User && $user->isApproved() && $user->role === 'admin';
    }

    public static function canViewAny(): bool { return static::allowed(); }
    public static function canCreate(): bool { return static::allowed(); }
    public static function canView(Model $record): bool { return static::allowed(); }
    public static function canEdit(Model $record): bool { return static::allowed(); }
    public static function canDelete(Model $record): bool { return false; }
    public static function canDeleteAny(): bool { return false; }

    public static function statuses(): array
    {
        return ['new' => 'Новая', 'in_progress' => 'В работе', 'review' => 'Проверка',
            'done' => 'Готово', 'overdue' => 'Просрочена', 'cancelled' => 'Отменена'];
    }

    public static function priorities(): array
    {
        return ['low' => 'Низкий', 'normal' => 'Обычный', 'high' => 'Высокий', 'urgent' => 'Срочный'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Название')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('description')->label('Описание')->maxLength(2000)->rows(4)->columnSpanFull(),
            Select::make('assignee_ids')->label('Исполнители')->multiple()->searchable()
                ->options(fn () => User::query()->activeStaff()->orderBy('name')->pluck('name', 'id')->all())
                ->required()->columnSpanFull(),
            DateTimePicker::make('starts_at')->label('Начало')->seconds(false),
            DateTimePicker::make('deadline_at')->label('Срок')->seconds(false),
            Select::make('priority')->label('Приоритет')->options(static::priorities())->default('normal')->required(),
            Select::make('status')->label('Статус')->options(static::statuses())->default('new')->visibleOn('edit')->required(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['assignee', 'assignees', 'board', 'room']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label('Задача')->searchable()->sortable(),
            TextColumn::make('assignees_label')->label('Исполнители')->state(fn (Task $record) => $record->assigneeNames()),
            TextColumn::make('starts_at')->label('Начало')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('deadline_at')->label('Срок')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('priority')->label('Приоритет')->formatStateUsing(fn (string $state) => static::priorities()[$state] ?? $state),
            TextColumn::make('status')->label('Статус')->badge()->formatStateUsing(fn (string $state) => static::statuses()[$state] ?? $state),
            TextColumn::make('board.title')->label('Доска')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('status')->label('Статус')->options(static::statuses()),
            SelectFilter::make('priority')->label('Приоритет')->options(static::priorities()),
        ])->defaultSort('created_at', 'desc')->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListTasks::route('/'), 'create' => CreateTask::route('/create'), 'edit' => EditTask::route('/{record}/edit')];
    }
}
