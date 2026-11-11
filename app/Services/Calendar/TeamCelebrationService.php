<?php

namespace App\Services\Calendar;

use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\Carbon;
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
                    photoPath: $this->birthdayCard($date),
                ));
            }

            $anniversaryYears = $this->anniversaryYears($user->work_started_at, $date);

            if ($anniversaryYears !== null) {
                $events->push($this->event(
                    kind: 'work_anniversary',
                    sourceType: 'User',
                    sourceId: (string) $user->getKey(),
                    date: $date,
                    text: $this->mention($user)
                        .' отмечает '.$anniversaryYears.' '.$this->yearsLabel($anniversaryYears)
                        .' в TRIS. Спасибо за вклад в команду!',
                ));
            }
        }

        $calendarEvents = $this->calendarEventsService
            ->getEventsForDay($date->toMutable(), 'all')
            ->filter(fn (array $event): bool => $this->isDigestCalendarEvent($event));

        foreach ($calendarEvents as $calendarEvent) {
            $kind = $calendarEvent['type'] === CalendarEvent::TYPE_HOLIDAY
                ? 'calendar_holiday'
                : (string) $calendarEvent['type'];
            $sourceId = (string) $calendarEvent['id'];
            $text = '<b>'.e((string) ($calendarEvent['title'] ?? 'Событие')).'</b>';
            $description = trim((string) ($calendarEvent['description'] ?? ''));

            if ($description !== '') {
                $text .= "\n".e($description);
            }

            $start = $calendarEvent['start'] ?? null;
            $end = $calendarEvent['end'] ?? null;
            if ($start instanceof Carbon && $end instanceof Carbon && ! $start->isSameDay($end)) {
                $dateRange = $start->year === $end->year
                    ? $start->format('d.m').'–'.$end->format('d.m')
                    : $start->format('d.m.Y').'–'.$end->format('d.m.Y');
                $text .= "\n<i>".$dateRange.'</i>';
            }

            $events->push($this->event(
                kind: $kind,
                sourceType: 'CalendarEvent',
                sourceId: $sourceId,
                date: $date,
                text: $text,
            ));
        }

        return $events->values();
    }

    /** @return array{text:string,photo_path:?string,over_limit:bool,character_count:int}|null */
    public function formatDigest(CarbonImmutable $date, Collection $events): ?array
    {
        if ($events->isEmpty()) {
            return null;
        }

        $headings = [
            'birthday' => '🎂 <b>Дни рождения</b>',
            'work_anniversary' => '🏆 <b>Годовщины работы</b>',
            'calendar_holiday' => '🎉 <b>Праздники</b>',
            CalendarEvent::TYPE_WORKFLOW => '🧭 <b>Рабочие процессы</b>',
            CalendarEvent::TYPE_FINANCE => '💶 <b>Финансы</b>',
            CalendarEvent::TYPE_PEAK => '📈 <b>Пиковая загрузка</b>',
            CalendarEvent::TYPE_STRIKE => '🚧 <b>Забастовки</b>',
        ];

        $sections = [];
        foreach ($headings as $kind => $heading) {
            $items = $events
                ->where('kind', $kind)
                ->map(fn (array $event): string => '• '.(string) $event['text'])
                ->values();

            if ($items->isNotEmpty()) {
                $sections[] = $heading."\n".$items->implode("\n");
            }
        }

        if ($sections === []) {
            return null;
        }

        $text = '📅 <b>События на '.$date->format('d.m.Y')."</b>\n\n".implode("\n\n", $sections);
        $characterCount = $this->telegramCharacterCount($text);
        $photoPath = $events->firstWhere('kind', 'birthday')['photo_path'] ?? null;
        if (! is_string($photoPath) || ! is_file($photoPath) || ! is_readable($photoPath)
            || $characterCount > 1024) {
            $photoPath = null;
        }

        return [
            'text' => $text,
            'photo_path' => $photoPath,
            'over_limit' => $characterCount > 4096,
            'character_count' => $characterCount,
        ];
    }

    protected function cardDirectory(): string
    {
        return resource_path('images/team-celebration-cards');
    }

    private function isDigestCalendarEvent(array $event): bool
    {
        $type = $event['type'] ?? null;
        $includedTypes = [
            CalendarEvent::TYPE_WORKFLOW,
            CalendarEvent::TYPE_FINANCE,
            CalendarEvent::TYPE_HOLIDAY,
            CalendarEvent::TYPE_PEAK,
            CalendarEvent::TYPE_STRIKE,
        ];

        return in_array($type, $includedTypes, true)
            && ($type !== CalendarEvent::TYPE_HOLIDAY || ($event['subtype'] ?? null) === 'calendar_holiday');
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

    private function birthdayCard(CarbonImmutable $date): ?string
    {
        $directory = $this->cardDirectory();

        if (! is_dir($directory)) {
            return null;
        }

        foreach (['jpg', 'jpeg', 'png'] as $extension) {
            $path = $directory.'/'.$date->format('m').'.'.$extension;

            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function telegramCharacterCount(string $html): int
    {
        $visibleText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return intdiv(strlen(mb_convert_encoding($visibleText, 'UTF-16LE', 'UTF-8')), 2);
    }
}
