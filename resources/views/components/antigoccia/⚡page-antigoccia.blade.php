<?php

use App\Models\AntigocciaApplication;
use App\Models\AntigocciaReport;
use App\Models\Apartment;
use App\Services\Antigoccia\AntigocciaAssessment;
use App\Services\Antigoccia\AntigocciaCleaningStatistics;
use App\Services\Apartments\ApartmentAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithFileUploads, WithPagination;

    public string $apartmentId = '';
    public string $cleaningDate = '';
    public string $daysSincePreviousCleaning = '';
    public string $submissionUuid = '';
    public array $answers = [];
    public array $photos = [];
    public bool $saved = false;
    public string $coatingDate = '';

    public function mount(): void
    {
        $this->newForm();
        $suggested = request()->query('apartment');
        if (ctype_digit((string) $suggested)
            && app(ApartmentAccessService::class)->visibleQuery(auth()->user())->whereKey((int) $suggested)->exists()) {
            $this->apartmentId = (string) $suggested;
        }
    }

    public function newForm(): void
    {
        $this->cleaningDate = now('Europe/Rome')->toDateString();
        $this->daysSincePreviousCleaning = '';
        $this->coatingDate = '';
        $this->answers = AntigocciaAssessment::blankAnswers();
        $this->photos = [];
        $this->submissionUuid = (string) Str::uuid();
        $this->saved = false;
        $this->resetValidation();
    }

    public function updatedApartmentId(): void
    {
        // Never carry answers, media or an application date to a different apartment.
        $this->newForm();
        $this->resetPage('antigocciaHistory');
    }

    public function setDirt(string $surface, int $score): void
    {
        if (! array_key_exists($surface, AntigocciaAssessment::SURFACES) || ! in_array($score, [0, 1, 2], true)) {
            return;
        }
        $previous = $this->answers[$surface]['dirt'] ?? null;
        $this->answers[$surface]['dirt'] = $score;
        if ($previous !== $score || $score === 0) {
            $this->answers[$surface]['removal'] = null;
        }
    }

    public function setRemoval(string $surface, int $score): void
    {
        if (! array_key_exists($surface, AntigocciaAssessment::SURFACES)
            || ! in_array($score, [0, 1, 2], true)
            || ! in_array((string) ($this->answers[$surface]['dirt'] ?? ''), ['1', '2'], true)) {
            return;
        }
        $this->answers[$surface]['removal'] = $score;
        if ($score === 2) {
            $this->answers[$surface]['remaining'] = 'yes';
        }
    }

    public function getApartmentsProperty()
    {
        return app(ApartmentAccessService::class)->visibleQuery(auth()->user())->orderBy('name')->get(['id', 'name', 'address']);
    }

    public function getApartmentProperty()
    {
        if (! ctype_digit($this->apartmentId)) {
            return null;
        }

        return app(ApartmentAccessService::class)->visibleQuery(auth()->user())->find((int) $this->apartmentId);
    }

    public function getApplicationsProperty()
    {
        return $this->apartment
            ? AntigocciaApplication::query()->where('apartment_id', $this->apartment->id)
                ->orderByDesc('applied_on')->orderByDesc('id')->get()
            : collect();
    }

    public function getCountersProperty(): array
    {
        $apartment = $this->apartment;
        $application = $this->applications->first();
        $recorded = $apartment
            ? app(AntigocciaCleaningStatistics::class)->forApartment($apartment, $application)
            : ['recorded_cleaning_days' => null, 'last_control_date' => null];

        return [
            'application' => $application,
            'days_since_application' => $application
                ? (int) abs(now('Europe/Rome')->startOfDay()->diffInDays($application->applied_on))
                : null,
            ...$recorded,
        ];
    }

    public function getHistoryProperty()
    {
        return $this->apartment
            ? AntigocciaReport::query()->where('apartment_id', $this->apartment->id)
                ->orderByDesc('cleaning_date')->orderByDesc('id')->paginate(20, ['*'], 'antigocciaHistory')
            : collect();
    }

    public function getAnalyticsProperty(): array
    {
        $application = $this->applications->first();
        $reports = $this->apartment ? AntigocciaReport::query()
            ->where('apartment_id', $this->apartment->id)
            ->when($application, fn ($query) => $query->whereDate('cleaning_date', '>=', $application->applied_on->toDateString()))
            ->orderBy('cleaning_date')->orderBy('id')->get() : collect();
        $result = [];

        foreach (AntigocciaAssessment::SURFACES as $key => $title) {
            $evaluated = $reports->filter(fn ($report) => data_get($report->answers, "$key.dirt") !== null);
            $difficult = $evaluated->filter(fn ($report) => in_array((string) data_get($report->answers, "$key.removal"), ['1', '2'], true));
            $failed = $evaluated->filter(fn ($report) => (string) data_get($report->answers, "$key.removal") === '2');
            $firstIssuePosition = $evaluated->values()->search(fn ($report) => in_array((string) data_get($report->answers, "$key.removal"), ['1', '2'], true));
            $recent = $evaluated->take(-12)->map(fn ($report) => [
                'date' => $report->cleaning_date->format('d.m.Y'),
                'score' => data_get($report->answers, "$key.removal"),
            ])->values()->all();
            $result[$key] = [
                'label' => $title,
                'total' => $evaluated->count(),
                'difficult' => $difficult->count(),
                'failed' => $failed->count(),
                'remaining_count' => $evaluated->filter(fn ($report) => data_get($report->answers, "$key.remaining") === 'yes')->count(),
                'first_issue_date' => $difficult->first()?->cleaning_date?->format('d.m.Y'),
                'first_issue_report_number' => $firstIssuePosition === false ? null : $firstIssuePosition + 1,
                'recent' => $recent,
            ];
        }

        return ['application' => $application, 'report_count' => $reports->count(), 'surfaces' => $result];
    }

    public function submit(AntigocciaAssessment $assessment): void
    {
        abort_unless(auth()->user()?->isApproved() && auth()->user()?->is_active, 403);
        if ($this->saved) {
            return;
        }

        $this->validate([
            'apartmentId' => ['required', 'integer'],
            'cleaningDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Rome')->toDateString()],
            'daysSincePreviousCleaning' => ['required', 'integer', 'min:0', 'max:36500'],
            'submissionUuid' => ['required', 'uuid'],
        ]);

        $apartment = $this->apartment;
        abort_unless($apartment, 403, 'Квартира недоступна.');
        $existing = AntigocciaReport::query()->where('submission_uuid', $this->submissionUuid)->first();
        if ($existing) {
            abort_unless($existing->user_id === auth()->id(), 403);
            $this->saved = true;

            return;
        }

        $existingApplication = AntigocciaApplication::query()->where('apartment_id', $apartment->id)
            ->orderByDesc('applied_on')->first();
        if (! $existingApplication) {
            $this->validate([
                'coatingDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->cleaningDate],
            ]);
        } elseif ($existingApplication->applied_on->toDateString() > $this->cleaningDate) {
            throw ValidationException::withMessages(['cleaningDate' => 'Дата уборки не может быть раньше обработки.']);
        }

        $answers = $assessment->validate($this->answers, $this->photos);
        $files = [];
        $uploadAttempt = (string) Str::uuid();

        try {
            foreach (AntigocciaAssessment::SURFACES as $surface => $label) {
                if ($this->photos[$surface] ?? null) {
                    $path = $this->photos[$surface]->store(
                        'antigoccia/'.$apartment->id.'/'.$this->submissionUuid.'/'.$uploadAttempt,
                        'local',
                    );
                    if (! $path) {
                        throw new \RuntimeException('Не удалось сохранить фотографию.');
                    }
                    $files[$surface] = $path;
                }
            }

            DB::transaction(function () use ($apartment, $answers, $files): void {
                // Serialize the first coating date for a given apartment. Later
                // forms cannot overwrite it, even when the client is tampered with.
                Apartment::query()->whereKey($apartment->id)->lockForUpdate()->firstOrFail();
                $application = AntigocciaApplication::query()->where('apartment_id', $apartment->id)
                    ->orderByDesc('applied_on')->first();
                if (! $application) {
                    $application = AntigocciaApplication::create([
                        'apartment_id' => $apartment->id,
                        'applied_on' => $this->coatingDate,
                        'created_by' => auth()->id(),
                    ]);
                }
                if ($application->applied_on->toDateString() > $this->cleaningDate) {
                    throw ValidationException::withMessages([
                        'cleaningDate' => 'Дата уборки не может быть раньше обработки.',
                    ]);
                }

                AntigocciaReport::create([
                    'apartment_id' => $apartment->id,
                    'user_id' => auth()->id(),
                    'cleaner_name' => auth()->user()->name ?: auth()->user()->telegram_username ?: 'Не указано',
                    'cleaning_date' => $this->cleaningDate,
                    'days_since_previous_cleaning' => (int) $this->daysSincePreviousCleaning,
                    'answers' => $answers,
                    'photos' => $files,
                    'remaining' => collect($answers)->contains(fn (array $answer): bool => $answer['remaining'] === 'yes'),
                    'submission_uuid' => $this->submissionUuid,
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete(array_values($files));
            throw $e;
        }

        $this->photos = [];
        $this->saved = true;
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] w-full items-center justify-between px-[15px]">
        <a href="{{ route('page-apartments') }}" class="flex h-[40px] w-[40px] items-center justify-center rounded-full bg-[#E9E9E9]" aria-label="К квартирам">
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px]" />
        </a>
        <span class="text-[18px]">ANTIGOCCIA</span>
        <div class="h-[40px] w-[40px]"></div>
    </div>
</x-slot:header>

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="min-h-full flex-1 overflow-y-auto rounded-t-[38px] bg-white px-[15px] pb-[110px] pt-[20px]">
        <h1 class="text-[29px] font-semibold">🧪 Антикапля</h1>
        <p class="mb-[20px] text-[13px] text-black/50">Контроль эффективности защитного покрытия.</p>

        <div class="space-y-[12px] rounded-[28px] bg-[#F6F6F6] p-[17px]">
            <label class="block text-[13px] font-semibold" for="antigoccia-apartment">🏠 Адрес</label>
            <select id="antigoccia-apartment" wire:model.live="apartmentId" class="w-full rounded-[17px] bg-white px-4 py-3 text-[15px]">
                <option value="">Выберите квартиру</option>
                @foreach($this->apartments as $apartment)
                    <option value="{{ $apartment->id }}">{{ $apartment->name }}</option>
                @endforeach
            </select>
            @error('apartmentId') <p role="alert" class="text-[12px] text-red-600">{{ $message }}</p> @enderror

            @if($this->apartment)
                <p class="text-[12px] text-black/60">{{ $this->apartment->address }}</p>
                @if($this->counters['application'])
                    <p class="text-[13px] font-semibold">📅 Дата обработки: {{ $this->counters['application']->applied_on->format('d.m.Y') }}</p>
                    <p class="text-[12px] text-black/45">Дата заполнена один раз и больше не редактируется.</p>
                @else
                    <label class="block text-[13px] font-semibold" for="antigoccia-coating-date">📅 Дата обработки (однократно)</label>
                    <input id="antigoccia-coating-date" type="date" wire:model="coatingDate" max="{{ $cleaningDate }}" class="w-full rounded-[17px] bg-white px-4 py-3 text-[15px]">
                    @error('coatingDate') <p role="alert" class="text-[12px] text-red-600">{{ $message }}</p> @enderror
                    <p class="text-[12px] text-black/45">Укажите при первом заполнении. После сохранения дата закрепится за квартирой.</p>
                @endif

                <div class="grid grid-cols-2 gap-[9px]">
                    <div class="rounded-[18px] bg-white p-3">
                        <p class="text-[11px] text-black/50">Времени после обработки</p>
                        <p class="mt-1 text-[21px] font-semibold">{{ $this->counters['days_since_application'] === null ? '—' : $this->counters['days_since_application'] }}</p>
                        <p class="text-[11px] text-black/45">дней</p>
                    </div>
                    <div class="rounded-[18px] bg-white p-3">
                        <p class="text-[11px] text-black/50">Уборок после последнего контроля</p>
                        <p class="mt-1 text-[21px] font-semibold">{{ $this->counters['recorded_cleaning_days'] === null ? '—' : $this->counters['recorded_cleaning_days'] }}</p>
                        <p class="text-[11px] text-black/45">зарегистрировано по датам</p>
                    </div>
                </div>
                <p class="text-[11px] text-black/45">Счётчики статичны и обновляются при открытии страницы. Уборки считаются по уникальным датам из записей контролей и анкет; это минимум зарегистрированных уборок, а не полный производственный табель.</p>
                @if($this->counters['last_control_date'])
                    <p class="text-[12px] text-black/55">Последний контроль: {{ \Carbon\CarbonImmutable::parse($this->counters['last_control_date'])->format('d.m.Y') }}</p>
                @endif
            @endif

            <label class="block text-[13px] font-semibold" for="antigoccia-date">📅 Дата текущей уборки</label>
            <input id="antigoccia-date" type="date" wire:model.live="cleaningDate" max="{{ now('Europe/Rome')->toDateString() }}" class="w-full rounded-[17px] bg-white px-4 py-3 text-[15px]">
            @error('cleaningDate') <p role="alert" class="text-[12px] text-red-600">{{ $message }}</p> @enderror

            <label class="block text-[13px] font-semibold" for="antigoccia-last-cleaning">Сколько дней назад была сделана последняя уборка?</label>
            <input id="antigoccia-last-cleaning" type="number" min="0" max="36500" step="1" inputmode="numeric" wire:model="daysSincePreviousCleaning" placeholder="Например, 7" class="w-full rounded-[17px] bg-white px-4 py-3 text-[15px]">
            @error('daysSincePreviousCleaning') <p role="alert" class="text-[12px] text-red-600">{{ $message }}</p> @enderror

            <p class="text-[13px] text-black/65">👤 Клинер: <span class="font-semibold">{{ auth()->user()->name ?: auth()->user()->telegram_username ?: 'Не указано' }}</span></p>
        </div>

        @if($saved)
            <div role="status" class="mt-[18px] rounded-[26px] bg-green-50 p-[18px] text-green-800">
                <p class="font-semibold">✅ Анкета сохранена</p>
                <p class="mt-2 text-[13px]">История и статистика квартиры обновлены.</p>
                <button type="button" wire:click="newForm" class="mt-4 rounded-full bg-white px-4 py-3 text-[14px] font-semibold">Заполнить ещё одну</button>
            </div>
        @else
            <form wire:submit="submit" class="mt-[18px] space-y-[12px]">
                @foreach(\App\Services\Antigoccia\AntigocciaAssessment::SURFACES as $surface => $label)
                    @php
                        $dirt = $answers[$surface]['dirt'] ?? null;
                        $removal = $answers[$surface]['removal'] ?? null;
                        $remaining = $answers[$surface]['remaining'] ?? '';
                        $canRemove = in_array((string) $dirt, ['1', '2'], true);
                    @endphp
                    <section wire:key="antigoccia-{{ $surface }}" class="rounded-[28px] bg-[#F6F6F6] p-[17px]">
                        <h2 class="mb-4 text-[18px] font-semibold">{{ $loop->iteration }}. {{ $label }}:</h2>
                        <p class="mb-2 text-[13px] font-medium">Степень загрязнения:</p>
                        <div class="grid grid-cols-3 gap-[7px]">
                            @foreach([0 => '🟢 0 — Нет загрязнений', 1 => '🟡 1 — Лёгкие', 2 => '🔴 2 — Сильные'] as $score => $text)
                                <button type="button" wire:click="setDirt('{{ $surface }}', {{ $score }})" aria-pressed="{{ (string) $dirt === (string) $score ? 'true' : 'false' }}" class="min-h-[54px] rounded-[16px] px-2 py-2 text-[12px] font-medium {{ (string) $dirt === (string) $score ? 'bg-[#213259] text-white' : 'bg-white text-black/75' }}">{{ $text }}</button>
                            @endforeach
                        </div>
                        @error('answers.'.$surface.'.dirt') <p role="alert" class="mt-2 text-[12px] text-red-600">{{ $message }}</p> @enderror

                        <p class="mb-2 mt-4 text-[13px] font-medium leading-[1.5]">Удаление загрязнений чистой влажной желтой салфеткой смоченной чистой водой:</p>
                        @if((string) $dirt === '0')
                            <p class="mb-2 text-[12px] text-green-700">Нет загрязнений — удаление не требовалось.</p>
                        @endif
                        <div class="grid grid-cols-3 gap-[7px]">
                            @foreach([0 => '🟢 0 — Легко', 1 => '🟡 1 — Трудно', 2 => '🔴 2 — Не удалились'] as $score => $text)
                                <button type="button" wire:click="setRemoval('{{ $surface }}', {{ $score }})" @disabled(!$canRemove) aria-pressed="{{ (string) $removal === (string) $score && $canRemove ? 'true' : 'false' }}" class="min-h-[54px] rounded-[16px] px-2 py-2 text-[12px] font-medium disabled:opacity-40 {{ (string) $removal === (string) $score && $canRemove ? 'bg-[#213259] text-white' : 'bg-white text-black/75' }}">{{ $text }}</button>
                            @endforeach
                        </div>
                        @error('answers.'.$surface.'.removal') <p role="alert" class="mt-2 text-[12px] text-red-600">{{ $message }}</p> @enderror

                        <div class="mt-4 border-t border-black/10 pt-4">
                            <p class="text-[14px] font-semibold">📸 ФОТО</p>
                            <p class="mb-3 mt-2 text-[14px]">Остались ЗАГРЯЗНЕНИЯ после уборки?</p>
                            <div class="flex gap-3">
                                <label class="rounded-[15px] bg-white px-4 py-3 text-[14px]">
                                    <input type="radio" wire:model.live="answers.{{ $surface }}.remaining" name="remaining-{{ $surface }}" value="yes" class="mr-1"> Да
                                </label>
                                <label class="rounded-[15px] bg-white px-4 py-3 text-[14px]">
                                    <input type="radio" wire:model.live="answers.{{ $surface }}.remaining" name="remaining-{{ $surface }}" value="no" class="mr-1"> Нет
                                </label>
                            </div>
                            @error('answers.'.$surface.'.remaining') <p role="alert" class="mt-2 text-[12px] text-red-600">{{ $message }}</p> @enderror
                            @if($remaining === 'yes')
                                <label class="mt-3 block text-[13px] font-semibold" for="photo-{{ $surface }}">Фото оставшихся загрязнений (обязательно)</label>
                                <input id="photo-{{ $surface }}" type="file" accept="image/jpeg,image/png,image/webp" wire:model="photos.{{ $surface }}" class="mt-2 w-full text-[13px]">
                                <p wire:loading wire:target="photos.{{ $surface }}" class="mt-2 text-[12px] text-blue-700">Загрузка фотографии…</p>
                                @if(isset($photos[$surface])) <p class="mt-2 text-[12px] text-green-700">Фото загружено ✓</p> @endif
                                @error('photos.'.$surface) <p role="alert" class="mt-2 text-[12px] text-red-600">{{ $message }}</p> @enderror
                            @endif
                        </div>
                    </section>
                @endforeach

                <button type="submit" wire:loading.attr="disabled" wire:target="submit,photos.*" class="w-full rounded-[22px] bg-[#213259] px-5 py-4 text-[16px] font-semibold text-white disabled:opacity-50">
                    <span wire:loading.remove wire:target="submit">✅ Сохранить анкету</span>
                    <span wire:loading wire:target="submit">Сохранение…</span>
                </button>
                <p class="text-center text-[11px] text-black/35">Фото хранятся в закрытом хранилище Academy.</p>
            </form>
        @endif

        @if($this->apartment)
            <div class="mt-[30px] border-t border-black/10 pt-[20px]">
                <h2 class="text-[22px] font-semibold">📊 Статистика покрытия</h2>
                <p class="mt-2 text-[13px] text-black/55">{{ $this->analytics['report_count'] }} анкет после обработки. Количество анкет не равно количеству всех уборок.</p>
                <div class="mt-3 space-y-2">
                    @foreach($this->analytics['surfaces'] as $surface)
                        <div class="rounded-[19px] bg-[#F6F6F6] p-4 text-[13px]">
                            <p class="font-semibold">{{ $surface['label'] }}</p>
                            <p class="mt-2 text-black/65">Трудно: {{ $surface['difficult'] }} · Не удалились: {{ $surface['failed'] }} · Остались загрязнения: {{ $surface['remaining_count'] }}</p>
                            @if($surface['first_issue_date'])
                                <p class="mt-1 text-black/50">Первое затруднение: {{ $surface['first_issue_date'] }}, анкета № {{ $surface['first_issue_report_number'] }} после обработки</p>
                            @endif
                            @if(count($surface['recent']) > 0)
                                <p class="mt-3 text-[11px] text-black/45">Удаляемость — последние {{ count($surface['recent']) }} анкет (0/1/2):</p>
                                <div class="mt-2 flex flex-wrap gap-[5px]" aria-label="История удаляемости">
                                    @foreach($surface['recent'] as $point)
                                        <span title="{{ $point['date'] }}" class="flex h-[30px] w-[30px] items-center justify-center rounded-[9px] text-[12px] font-semibold {{ $point['score'] === null ? 'bg-gray-200 text-gray-600' : ($point['score'] === 0 ? 'bg-green-100 text-green-800' : ($point['score'] === 1 ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800')) }}">{{ $point['score'] === null ? '—' : $point['score'] }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-[30px] border-t border-black/10 pt-5">
                <h2 class="text-[22px] font-semibold">📋 История квартиры</h2>
                @forelse($this->history as $report)
                    <details class="mt-3 rounded-[23px] bg-[#F6F6F6] p-[15px]">
                        <summary class="cursor-pointer text-[14px] font-semibold">{{ $report->cleaning_date->format('d.m.Y') }} · {{ $report->cleaner_name }}</summary>
                        <p class="mt-3 text-[12px] text-black/45">Заполнено {{ $report->created_at->format('d.m.Y H:i') }} · Последняя уборка до этой: {{ $report->days_since_previous_cleaning === null ? 'не указано' : $report->days_since_previous_cleaning.' дн. назад' }}</p>
                        @foreach(\App\Services\Antigoccia\AntigocciaAssessment::SURFACES as $surface => $label)
                            @php $a = $report->answers[$surface] ?? []; @endphp
                            <div class="mt-3 border-t border-black/5 pt-2 text-[13px]">
                                <p class="font-medium">{{ $label }}</p>
                                <p class="text-black/55">Загрязнение: {{ $a['dirt'] ?? '—' }} · Удаление: {{ ($a['removal'] ?? null) === null ? 'Не требовалось' : $a['removal'] }} · Остались: {{ ($a['remaining'] ?? null) === null ? 'Не указано' : ($a['remaining'] === 'yes' ? 'Да' : 'Нет') }}</p>
                                @if(!empty($report->photos[$surface]))
                                    <a target="_blank" rel="noopener" href="{{ route('antigoccia.photo', [$report, $surface]) }}" class="mt-1 inline-block font-medium text-[#213259] underline">Открыть фото</a>
                                @endif
                            </div>
                        @endforeach
                        @if(!empty($report->photos['remaining']))
                            <p class="mt-3 text-[12px] text-black/50">Старая анкета: общий ответ «{{ $report->remaining ? 'Да' : 'Нет' }}»</p>
                            <a target="_blank" rel="noopener" href="{{ route('antigoccia.photo', [$report, 'remaining']) }}" class="text-[13px] text-[#213259] underline">Общее фото</a>
                        @endif
                    </details>
                @empty
                    <p class="mt-3 text-[13px] text-black/50">Заполненных анкет пока нет.</p>
                @endforelse
                @if($this->history instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->history->hasPages())
                    <div class="mt-4">{{ $this->history->links() }}</div>
                @endif
            </div>
        @endif
    </div>
</div>

