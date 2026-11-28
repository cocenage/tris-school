<?php

namespace App\Filament\Support;

use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Apartments\ApartmentResource;
use App\Filament\Resources\ControlResponses\ControlResponseResource;
use App\Filament\Resources\DayOffRequests\DayOffRequestResource;
use App\Filament\Resources\VacationRequests\VacationRequestResource;
use App\Filament\Resources\InventoryRequests\InventoryRequestResource;
use App\Filament\Resources\SalaryQuestions\SalaryQuestionResource;
use App\Filament\Resources\ScheduleQuestions\ScheduleQuestionResource;
use App\Filament\Resources\FeedbackSuggestions\FeedbackSuggestionResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\SupervisorEmployees\SupervisorEmployeeResource;
use App\Models\Apartment;
use App\Models\ControlResponse;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;
use Spatie\Activitylog\Models\Activity;

final class RecordConnections
{
    public static function render(Model $record): HtmlString
    {
        $links = [];
        $panel = Filament::getCurrentPanel();
        if (! auth()->user()?->isApproved() || ! $panel || ! auth()->user()->canAccessPanel($panel)) {
            return new HtmlString('');
        }

        if ($record instanceof User) {
            self::filtered($links, ControlResponseResource::class, 'cleaner_id', $record->getKey(), 'Контроли сотрудника', 'heroicon-o-clipboard-document-check');
            self::filtered($links, ControlResponseResource::class, 'supervisor_id', $record->getKey(), 'Проведённые проверки', 'heroicon-o-clipboard-document-list');
            foreach ([
                [DayOffRequestResource::class, 'Выходные'],
                [VacationRequestResource::class, 'Отпуска'],
                [InventoryRequestResource::class, 'Инвентарь'],
                [ScheduleQuestionResource::class, 'Вопросы по графику'],
                [SalaryQuestionResource::class, 'Вопросы по зарплате'],
                [FeedbackSuggestionResource::class, 'Обратная связь'],
            ] as [$resource, $label]) {
                self::filtered($links, $resource, 'user_id', $record->getKey(), $label, 'heroicon-o-inbox');
            }
            if (auth()->user()->isAdmin()) {
                self::filtered($links, ActivityResource::class, 'causer_id', $record->getKey(), 'Активность', 'heroicon-o-clock', false);
            }
        } elseif ($record instanceof Apartment) {
            self::filtered($links, ControlResponseResource::class, 'apartment_id', $record->getKey(), 'Контроли квартиры', 'heroicon-o-clipboard-document-check');
        } elseif ($record instanceof ControlResponse) {
            self::record($links, ApartmentResource::class, $record->apartment_id, 'Квартира', 'heroicon-o-home-modern');
            $employeeResource = $panel->getId() === 'education' ? SupervisorEmployeeResource::class : UserResource::class;
            self::record($links, $employeeResource, $record->cleaner_id, 'Клинер', 'heroicon-o-user');
            self::record($links, $employeeResource, $record->supervisor_id, 'Супервайзер', 'heroicon-o-user');
        } elseif ($record->getAttribute('user_id')) {
            $employeeResource = $panel->getId() === 'education' ? SupervisorEmployeeResource::class : UserResource::class;
            self::record($links, $employeeResource, $record->getAttribute('user_id'), 'Сотрудник', 'heroicon-o-user');
        }

        return new HtmlString(view('filament.shared.record-connections', compact('links'))->render());
    }

    public static function history(Model $record): HtmlString
    {
        $events = [];
        $available = auth()->user()?->isAdmin() && Filament::getCurrentPanel()?->getId() === 'admin';
        if ($available && Schema::connection((new Activity())->getConnectionName())->hasTable((new Activity())->getTable())) {
            $events = Activity::query()->where('subject_type', $record->getMorphClass())
                ->where('subject_id', $record->getKey())->with('causer')->latest('id')->limit(12)->get()
                ->map(fn (Activity $event) => [
                    'label' => match ($event->event) {
                        'created' => 'Запись создана',
                        'updated' => 'Запись изменена',
                        'deleted' => 'Запись удалена',
                        'day_off_request_created', 'vacation_request_created', 'inventory_request_created' => 'Заявка создана',
                        'control_completed' => 'Контроль завершён',
                        default => 'Действие в журнале',
                    },
                    'actor' => $event->causer instanceof User ? $event->causer->name : 'Система',
                    'time' => $event->created_at?->setTimezone('Europe/Rome')->format('d.m.Y H:i'),
                ])->all();
        }
        return new HtmlString(view('filament.shared.record-history', compact('events', 'available'))->render());
    }

    private static function allowed(string $resource): bool
    {
        return in_array($resource, Filament::getCurrentPanel()?->getResources() ?? [], true) && $resource::canViewAny();
    }

    private static function filtered(array &$links, string $resource, string $field, int|string $id, string $label, string $icon, bool $count = true): void
    {
        if (! self::allowed($resource)) {
            return;
        }
        $links[] = [
            'label' => $label, 'icon' => $icon,
            'value' => $count ? $resource::getEloquentQuery()->reorder()->where($field, $id)->count() : null,
            'url' => $resource::getUrl('index', ['tableFilters' => [$field => ['value' => (string) $id]]]),
        ];
    }

    private static function record(array &$links, string $resource, mixed $id, string $label, string $icon): void
    {
        if (! $id || ! self::allowed($resource)) {
            return;
        }
        $related = $resource::getEloquentQuery()->whereKey($id)->first();
        if (! $related || ! $resource::canView($related)) {
            return;
        }
        $links[] = ['label' => $label, 'icon' => $icon, 'value' => $related->name, 'url' => $resource::getUrl('view', ['record' => $related])];
    }
}
