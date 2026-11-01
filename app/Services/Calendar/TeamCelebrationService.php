<?php

namespace App\Services\Calendar;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class TeamCelebrationService
{
    private const BIRTHDAY_GREETINGS = [
        '🎂 С днём рождения, %s! Желаем радости, здоровья и прекрасного года!',
        '🎂 Поздравляем %s с днём рождения! Пусть впереди будет много светлых моментов!',
        '🎂 С днём рождения, %s! Желаем вдохновения, тепла и исполнения желаний!',
    ];

    public function __construct(private readonly CalendarEventsService $calendarEventsService) {}

    /** @return Collection<int, array{kind:string,operation_key:string,source_type:string,source_id:string,text:string,photo_path:?string}> */
    public function eventsForDate(CarbonImmutable $date): Collection
    {
        $date = $date->startOfDay();
        $events = collect();

        $users = User::query()
            ->activeStaff()
            ->where('role', '!=', 'admin')
            ->where(function ($query) {
                $query->whereNotNull('birthday')->orWhereNotNull('work_started_at');
            })
            ->orderBy('name')
            ->get();

        foreach ($users as $user) {
            if ($user->birthday && $this->matchesAnnualDate($user->birthday, $date)) {
                $events->push($this->event(
                    kind: 'birthday',
                    sourceType: 'User',
                    sourceId: (string) $user->getKey(),
                    date: $date,
                    text: sprintf(Arr::random(self::BIRTHDAY_GREETINGS), $this->mention($user)),
                    photoPath: $this->birthdayCard(),
                ));
            }

            $anniversaryYears = $this->anniversaryYears($user->work_started_at, $date);

            if ($anniversaryYears !== null) {
                $events->push($this->event(
                    kind: 'work_anniversary',
                    sourceType: 'User',
                    sourceId: (string) $user->getKey(),
                    date: $date,
                    text: '🏆 <b>Годовщина работы</b>'."\nСегодня ".$this->mention($user)
                        .' отмечает '.$anniversaryYears.' '.$this->yearsLabel($anniversaryYears)
                        .' в TRIS. Спасибо за вклад в команду!',
                ));
            }
        }

        $calendarHolidays = $this->calendarEventsService
            ->getEventsForDay($date->toMutable(), 'holiday')
            ->filter(fn (array $event) => ($event['subtype'] ?? null) === 'calendar_holiday'
                && ($event['start'] ?? null)?->toDateString() === $date->toDateString());

        foreach ($calendarHolidays as $holiday) {
            $sourceId = (string) $holiday['id'];
            $events->push($this->event(
                kind: 'calendar_holiday',
                sourceType: 'CalendarEvent',
                sourceId: $sourceId,
                date: $date,
                text: '🎉 <b>'.e((string) ($holiday['title'] ?? 'Праздник')).'</b>',
            ));
        }

        return $events->values();
    }

    protected function cardDirectory(): string
    {
        return resource_path('images/team-celebration-cards');
    }

    private function event(
        string $kind,
        string $sourceType,
        string $sourceId,
        CarbonImmutable $date,
        string $text,
        ?string $photoPath = null,
    ): array {
        $route = implode('|', [
            (string) config('services.telegram.chat_id_celebrations', ''),
            (string) config('services.telegram.thread_id_celebrations', ''),
        ]);

        return [
            'kind' => $kind,
            'operation_key' => implode(':', [
                'team_celebration',
                $kind,
                $sourceId,
                $date->toDateString(),
                substr(hash('sha256', $route), 0, 20),
            ]),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'text' => $text,
            'photo_path' => $photoPath,
        ];
    }

    private function mention(User $user): string
    {
        $name = e((string) $user->name);

        if (is_numeric($user->telegram_id) && (int) $user->telegram_id > 0) {
            return '<a href="tg://user?id='.(int) $user->telegram_id.'">'.$name.'</a>';
        }

        $username = ltrim((string) $user->telegram_username, '@');

        if (preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) === 1) {
            return '<a href="https://t.me/'.$username.'">'.$name.'</a>';
        }

        return $name;
    }

    private function matchesAnnualDate(mixed $sourceDate, CarbonImmutable $date): bool
    {
        $source = CarbonImmutable::parse($sourceDate, 'Europe/Rome');
        $day = $source->month === 2 && $source->day === 29 && ! $date->isLeapYear()
            ? 28
            : $source->day;

        return $source->month === $date->month && $day === $date->day;
    }

    private function anniversaryYears(mixed $workStartedAt, CarbonImmutable $date): ?int
    {
        if (! $workStartedAt) {
            return null;
        }

        $started = CarbonImmutable::parse($workStartedAt, 'Europe/Rome');
        if (! $this->matchesAnnualDate($started, $date)) {
            return null;
        }

        $years = $date->year - $started->year;

        return $years > 0 ? $years : null;
    }

    private function yearsLabel(int $years): string
    {
        $lastTwo = $years % 100;
        $last = $years % 10;

        return $lastTwo >= 11 && $lastTwo <= 14
            ? 'лет'
            : match (true) {
                $last === 1 => 'год',
                $last >= 2 && $last <= 4 => 'года',
                default => 'лет',
            };
    }

    private function birthdayCard(): ?string
    {
        $directory = $this->cardDirectory();

        if (! is_dir($directory)) {
            return null;
        }

        $files = collect(glob($directory.'/*') ?: [])
            ->filter(fn (string $path) => is_file($path)
                && is_readable($path)
                && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true))
            ->values();

        return $files->isEmpty() ? null : Arr::random($files->all());
    }
}
