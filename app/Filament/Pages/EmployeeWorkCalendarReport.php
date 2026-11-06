<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Calendar\EmployeeWorkCalendarStatisticsService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeWorkCalendarReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Рабочий календарь';

    protected static ?string $title = 'Рабочий календарь сотрудников';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.employee-work-calendar-report';

    public string $periodType = 'month';

    public ?string $month = null;

    public ?string $year = null;

    public ?string $employeeId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->canViewAllEmployeeCalendars() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return Filament::getCurrentPanel()?->getId() === 'education'
            ? 'Сотрудники и графики'
            : 'Пользователи';
    }

    public function mount(): void
    {
        $today = CarbonImmutable::now(config('app.timezone', 'Europe/Rome'));
        $this->month = $today->format('Y-m');
        $this->year = $today->format('Y');
    }

    public function getCalendarReportProperty(): array
    {
        [$from, $through] = $this->period();

        return app(EmployeeWorkCalendarStatisticsService::class)->forPeriod(
            $from,
            $through,
            $this->selectedEmployeeId(),
        );
    }

    public function getEmployeeOptionsProperty(): array
    {
        return User::query()
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->where('status', 'approved')
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(auth()->user()?->canExportEmployeeCalendars(), 403);

        $report = $this->calendarReport;
        $filename = 'employee-work-calendar-'.$report['from'].'-'.$report['through'].'.csv';

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Сотрудник', 'Роль', 'Плановые рабочие дни', 'Регулярные выходные', 'График не зафиксирован', 'До начала работы', 'Одобренные отсутствия', 'Отгулы', 'Отпуск', 'Неоплачиваемый отпуск', 'Прочие отсутствия', 'Ожидающие дни заявок', 'Дни ручных изменений'], ',', '"', '');

            foreach ($report['employees'] as $row) {
                fputcsv($stream, [
                    $this->safeCsvCell($row['employee_name']),
                    $row['role'],
                    $row['planned_workdays'],
                    $row['regular_off_days'],
                    $row['unknown_schedule_days'],
                    $row['not_employed_days'],
                    $row['approved_absence_days'],
                    $row['approved_day_off_days'],
                    $row['approved_vacation_days'],
                    $row['approved_unpaid_leave_days'],
                    $row['other_approved_absence_days'],
                    $row['pending_request_days'],
                    $row['manual_change_days'],
                ], ',', '"', '');
            }

            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function period(): array
    {
        if ($this->periodType === 'year') {
            if (! is_string($this->year)
                || ! preg_match('/^\d{4}$/', $this->year)
                || (int) $this->year < 2000
                || (int) $this->year > 2100) {
                throw ValidationException::withMessages(['year' => 'Выберите корректный год.']);
            }

            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->year.'-01-01', config('app.timezone', 'Europe/Rome'));

            return [$from, $from->endOfYear()];
        }

        if (! is_string($this->month) || ! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            throw ValidationException::withMessages(['month' => 'Выберите корректный месяц.']);
        }

        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->month.'-01', config('app.timezone', 'Europe/Rome'));
        } catch (\Throwable) {
            $from = null;
        }

        if (! $from || $from->format('Y-m') !== $this->month) {
            throw ValidationException::withMessages(['month' => 'Выберите существующий месяц.']);
        }

        return [$from, $from->endOfMonth()];
    }

    private function selectedEmployeeId(): ?int
    {
        if ($this->employeeId === null || $this->employeeId === '') {
            return null;
        }

        if (! ctype_digit($this->employeeId) || ! array_key_exists((int) $this->employeeId, $this->employeeOptions)) {
            abort(404);
        }

        return (int) $this->employeeId;
    }

    private function safeCsvCell(string $value): string
    {
        return preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value;
    }
}
