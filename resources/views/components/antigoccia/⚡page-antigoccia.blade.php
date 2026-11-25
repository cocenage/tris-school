<?php

use App\Models\AntigocciaApplication;
use App\Models\AntigocciaReport;
use App\Models\Apartment;
use App\Services\Antigoccia\AntigocciaAssessment;
use App\Services\Antigoccia\DeleteOwnAntigocciaReport;
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
    public string $apartmentSearch = '';
    public string $cleaningDate = '';
    public string $daysSincePreviousCleaning = '';
    public string $submissionUuid = '';
    public array $answers = [];
    public array $photos = [];
    public bool $saved = false;
    public bool $showMyReports = false;
    public ?int $deleteCandidateId = null;
    public string $coatingDate = '';

    public function mount(): void
    {
        $this->newForm();
        $suggested = request()->query('apartment');
        if (ctype_digit((string) $suggested)
            && app(ApartmentAccessService::class)->visibleQuery(auth()->user())->whereKey((int) $suggested)->exists()) {
            $this->selectApartment((int) $suggested);
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

    public function selectApartment(int $id): void
    {
        $apartment = app(ApartmentAccessService::class)->visibleQuery(auth()->user())
            ->whereKey($id)->first();

        abort_unless($apartment, 403);

        $this->newForm();
        $this->apartmentId = (string) $id;
        $this->apartmentSearch = '';
    }

    public function clearApartment(): void
    {
        $this->apartmentId = '';
        $this->apartmentSearch = '';
        $this->newForm();
    }

    public function getApartmentMatchesProperty()
    {
        $search = mb_strtolower(trim($this->apartmentSearch));
        return app(ApartmentAccessService::class)->visibleQuery(auth()->user())
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])
                    ->orWhereRaw("LOWER(COALESCE(address, '')) LIKE ?", ['%'.$search.'%']);
            }))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'address']);
    }

    public function setDirt(string $surface, int $score): void
    {
        if (! array_key_exists($surface, AntigocciaAssessment::SURFACES) || ! in_array($score, [0, 1, 2], true)) {
            return;
        }

        $this->answers[$surface]['dirt'] = $score;
    }

    public function setRemoval(string $surface, string $value): void
    {
        if (! array_key_exists($surface, AntigocciaAssessment::SURFACES)
            || ! array_key_exists($value, AntigocciaAssessment::REMOVAL_OPTIONS)) {
            return;
        }

        $this->answers[$surface]['removal'] = $value;
    }

    public function getApartmentProperty()
    {
        if (! ctype_digit($this->apartmentId)) {
            return null;
        }

        return app(ApartmentAccessService::class)->visibleQuery(auth()->user())
            ->find((int) $this->apartmentId);
    }

    public function getApplicationProperty(): ?AntigocciaApplication
    {
        if (! $this->apartment) {
            return null;
        }

        return AntigocciaApplication::query()->where('apartment_id', $this->apartment->id)
            ->orderByDesc('applied_on')->orderByDesc('id')->first();
    }

    /**
     * Presentation-only state: answers stay independent and the assessment
     * service remains the authority for validation and required photos.
     */
    public function getCompletionProperty(): array
    {
        $items = [];
        $completed = 0;

        foreach (AntigocciaAssessment::SURFACES as $surface => $label) {
            $answer = $this->answers[$surface] ?? [];
            $dirt = $answer['dirt'] ?? null;
            $removal = $answer['removal'] ?? null;
            $remaining = $answer['remaining'] ?? null;

            $answered = (int) in_array($dirt, [0, 1, 2], true)
                + (int) (is_string($removal) && array_key_exists($removal, AntigocciaAssessment::REMOVAL_OPTIONS))
                + (int) in_array($remaining, ['yes', 'no'], true);

            $needsPhoto = $remaining === 'yes';
            $hasPhoto = isset($this->photos[$surface]) && $this->photos[$surface] !== null;
            $complete = $answered === 3 && (! $needsPhoto || $hasPhoto);

            if ($complete) {
                $completed++;
            }

            $items[$surface] = [
                'answered' => $answered,
                'complete' => $complete,
                'needs_photo' => $needsPhoto,
                'has_photo' => $hasPhoto,
                'label' => $needsPhoto && ! $hasPhoto && $answered === 3
                    ? 'Нужно фото'
                    : ($complete
                        ? ($needsPhoto ? 'Есть остатки' : 'Заполнено')
                        : ($answered > 0 ? 'В процессе' : 'Не начато')),
                'tone' => $needsPhoto && ($complete || $answered === 3)
                    ? 'danger'
                    : ($complete ? 'success' : ($answered > 0 ? 'progress' : 'idle')),
            ];
        }

        return [
            'completed' => $completed,
            'total' => count(AntigocciaAssessment::SURFACES),
            'items' => $items,
        ];
    }

    public function removePhoto(string $surface): void
    {
        if (! array_key_exists($surface, AntigocciaAssessment::SURFACES) || $this->saved) {
            return;
        }

        unset($this->photos[$surface]);
        $this->resetValidation('photos.'.$surface);
    }

    public function getMyReportsProperty()
    {
        return AntigocciaReport::query()
            ->with('apartment:id,name')
            ->where('user_id', auth()->id())
            ->latest('created_at')->latest('id')
            ->paginate(10, ['id', 'apartment_id', 'user_id', 'cleaning_date', 'created_at', 'remaining'], 'antigocciaMine');
    }

    public function requestDelete(int $id): void
    {
        abort_unless(auth()->check() && auth()->user()->isApproved() && auth()->user()->is_active, 403);
        abort_unless(AntigocciaReport::query()
            ->whereKey($id)->where('user_id', auth()->id())->exists(), 404);

        $this->deleteCandidateId = $id;
        $this->showMyReports = true;
    }

    public function cancelDelete(): void
    {
        $this->deleteCandidateId = null;
    }

    public function deleteMyReport(DeleteOwnAntigocciaReport $deletion): void
    {
        abort_unless(auth()->check(), 403);
        abort_unless($this->deleteCandidateId !== null, 400);

        $current = AntigocciaReport::query()
            ->whereKey($this->deleteCandidateId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        $wasCurrentSubmission = $current->submission_uuid === $this->submissionUuid;
        $deletion->delete(auth()->user(), (int) $this->deleteCandidateId);
        $this->deleteCandidateId = null;
        $this->resetPage('antigocciaMine');

        if ($wasCurrentSubmission) {
            $this->newForm();
        }

        $this->dispatch('toast', type: 'success', title: 'Ответ удалён');
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
    <div class="flex h-[70px] w-full items-center justify-between px-4">
        <a href="{{ route('page-checks') }}" aria-label="Назад к контролям"
            class="flex size-11 items-center justify-center rounded-full bg-[#E9EDF3] text-[#20386B] transition-colors hover:bg-[#DFE8F4] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#20386B]">
            <x-heroicon-o-arrow-left class="size-5" />
        </a>
        <span class="text-[16px] font-bold tracking-[-0.02em] text-[#20386B]">Антикапля</span>
        <div class="size-11" aria-hidden="true"></div>
    </div>
</x-slot:header>

<div class="h-full min-h-0 w-full overflow-y-auto bg-[#F2F2F2]">
    <main class="mx-auto flex w-full max-w-[840px] flex-col gap-4 px-4 pb-[calc(112px+env(safe-area-inset-bottom))] pt-4 sm:px-6">
        <section class="relative overflow-hidden rounded-[32px] bg-gradient-to-br from-[#20386B] via-[#26558E] to-[#3181BB] px-6 py-6 text-white shadow-[0_12px_30px_rgba(32,56,107,0.14)] sm:px-8">
            <div aria-hidden="true" class="pointer-events-none absolute -right-12 -top-16 size-56 rounded-full border border-white/15"></div>
            <div aria-hidden="true" class="pointer-events-none absolute -bottom-20 right-8 size-48 rounded-full border border-white/10"></div>
            <div class="relative">
                <div class="mb-4 flex items-center gap-2">
                    <span class="flex size-9 items-center justify-center rounded-[13px] bg-white/15">
                        <x-heroicon-o-sparkles class="size-5" />
                    </span>
                    <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-white/80">Контроль покрытия</span>
                </div>
                <h1 class="text-[30px] font-bold leading-[1.1] tracking-[-0.045em] sm:text-[36px]">Антикапля</h1>
                <p class="mt-3 max-w-[440px] text-[14px] leading-6 text-white/85">Проверьте четыре поверхности после уборки и зафиксируйте состояние покрытия.</p>
                <div class="mt-6 flex flex-wrap items-center gap-2">
                    <span class="rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-[12px] font-semibold">4 поверхности</span>
                    <span class="rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-[12px] font-semibold">3 ответа на каждую</span>
                </div>
            </div>
        </section>

        @if($saved)
            <section role="status" class="flex items-start gap-4 rounded-[28px] border border-emerald-200 bg-[#F0FAF4] p-5 text-emerald-900 shadow-sm sm:p-6">
                <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-100">
                    <x-heroicon-o-check-circle class="size-7" />
                </div>
                <div class="min-w-0 flex-1">
                    <h2 class="text-[19px] font-bold">Анкета сохранена</h2>
                    <p class="mt-1 text-[13px] leading-5 text-emerald-800/80">Результат записан. Можете заполнить ещё одну анкету или открыть историю ниже.</p>
                    <button type="button" wire:click="newForm"
                        class="mt-4 inline-flex min-h-11 items-center justify-center rounded-full bg-[#20386B] px-5 text-[14px] font-semibold text-white transition-colors hover:bg-[#2D5B95] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-800">
                        Новая анкета
                    </button>
                </div>
            </section>
        @endif

        <section class="rounded-[30px] bg-white p-5 shadow-[0_3px_22px_rgba(25,40,65,0.04)] sm:p-6">
            <div class="mb-5 flex items-center gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-[#EDF3FA] text-[#20386B]">
                    <x-heroicon-o-map-pin class="size-5" />
                </span>
                <div>
                    <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-[#69788F]">Шаг 01</p>
                    <h2 class="text-[19px] font-bold tracking-tight text-[#1D2C47]">Данные уборки</h2>
                </div>
            </div>

            <div class="space-y-4">
                <div>
                    <label for="antigoccia-apartment-search" class="mb-2 block text-[13px] font-semibold text-[#344460]">Квартира <span class="text-[#C84C46]">*</span></label>
                    @if($this->apartment)
                        <div class="flex items-center justify-between gap-3 rounded-[21px] border border-[#D6E4F3] bg-[#F3F7FC] p-3.5">
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="flex size-10 shrink-0 items-center justify-center rounded-[14px] bg-white text-[#376AA0]">
                                    <x-heroicon-o-building-office-2 class="size-5" />
                                </div>
                                <div class="min-w-0">
                                    <p class="truncate text-[14px] font-bold text-[#20386B]">{{ $this->apartment->name }}</p>
                                    @if($this->apartment->address)
                                        <p class="mt-0.5 text-[12px] leading-5 text-[#63728A]">{{ $this->apartment->address }}</p>
                                    @endif
                                    <p class="mt-1 text-[11px] font-semibold text-[#34745E]">Выбрана</p>
                                </div>
                            </div>
                            @unless($saved)
                                <button type="button" wire:click="clearApartment"
                                    class="min-h-10 shrink-0 rounded-full bg-white px-3 text-[12px] font-bold text-[#20386B] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#20386B]">Изменить</button>
                            @endunless
                        </div>
                    @else
                        <div class="relative">
                            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-4 top-3.5 size-5 text-[#8795A8]" />
                            <input id="antigoccia-apartment-search" type="search" wire:model.live.debounce.250ms="apartmentSearch"
                                placeholder="Название квартиры или адрес" autocomplete="off"
                                role="combobox" aria-autocomplete="list" aria-controls="antigoccia-apartment-options" aria-expanded="true"
                                class="min-h-[52px] w-full rounded-[17px] border border-[#E0E6ED] bg-[#F7F9FB] py-3 pl-11 pr-4 text-[14px] text-[#1D2C47] outline-none placeholder:text-[#8A96A8] focus:border-[#3181BB] focus:ring-2 focus:ring-[#3181BB]/20">
                        </div>
                        <div id="antigoccia-apartment-options" role="listbox" class="mt-2 max-h-[230px] space-y-1 overflow-y-auto rounded-[20px] border border-[#E8EDF3] p-1.5">
                            <p wire:loading wire:target="apartmentSearch" class="px-3 py-2 text-[12px] text-[#63728A]">Ищем квартиры…</p>
                            @forelse($this->apartmentMatches as $option)
                                <button type="button" role="option" aria-selected="false"
                                    wire:key="antigoccia-apartment-option-{{ $option->id }}" wire:click="selectApartment({{ $option->id }})"
                                    class="flex min-h-12 w-full items-center gap-3 rounded-[14px] px-3 py-2 text-left transition-colors hover:bg-[#F0F5FA] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#3181BB]">
                                    <x-heroicon-o-building-office-2 class="size-4 shrink-0 text-[#667D9A]" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[13px] font-semibold text-[#253854]">{{ $option->name }}</span>
                                        @if($option->address)<span class="block truncate text-[11px] text-[#7D8AA0]">{{ $option->address }}</span>@endif
                                    </span>
                                    <x-heroicon-o-chevron-right class="size-4 shrink-0 text-[#A0AAB7]" />
                                </button>
                            @empty
                                <p class="px-3 py-4 text-center text-[13px] text-[#7D8AA0]">Ничего не найдено. Попробуйте другой адрес.</p>
                            @endforelse
                        </div>
                    @endif
                    @error('apartmentId') <p role="alert" class="mt-2 text-[12px] font-medium text-red-700">{{ $message }}</p> @enderror
                </div>

                @if($this->apartment)
                    @if($this->application)
                        <div class="flex items-center justify-between gap-3 rounded-[17px] bg-[#F4F8FC] px-4 py-3">
                            <span class="text-[13px] text-[#64758D]">Покрытие нанесено</span>
                            <span class="text-[13px] font-bold text-[#20386B]">{{ $this->application->applied_on->format('d.m.Y') }}</span>
                        </div>
                    @else
                        <div>
                            <label for="antigoccia-coating-date" class="mb-2 block text-[13px] font-semibold text-[#344460]">Дата обработки <span class="text-[#C84C46]">*</span></label>
                            <input id="antigoccia-coating-date" type="date" wire:model="coatingDate" max="{{ $cleaningDate }}"
                                @disabled($saved)
                                class="min-h-[52px] w-full rounded-[17px] border border-[#E0E6ED] bg-[#F7F9FB] px-4 text-[14px] text-[#253854] focus:border-[#3181BB] focus:ring-[#3181BB]/20 disabled:opacity-60">
                            @error('coatingDate') <p role="alert" class="mt-2 text-[12px] text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endif
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="antigoccia-date" class="mb-2 block text-[13px] font-semibold text-[#344460]">Дата уборки <span class="text-[#C84C46]">*</span></label>
                        <input id="antigoccia-date" type="date" wire:model.live="cleaningDate"
                            max="{{ now('Europe/Rome')->toDateString() }}" @disabled($saved)
                            class="min-h-[52px] w-full rounded-[17px] border border-[#E0E6ED] bg-[#F7F9FB] px-4 text-[14px] text-[#253854] focus:border-[#3181BB] focus:ring-[#3181BB]/20 disabled:opacity-60">
                        @error('cleaningDate') <p role="alert" class="mt-2 text-[12px] text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="antigoccia-last-cleaning" class="mb-2 block text-[13px] font-semibold text-[#344460]">Дней с прошлой уборки <span class="text-[#C84C46]">*</span></label>
                        <input id="antigoccia-last-cleaning" type="number" min="0" max="36500" step="1" inputmode="numeric"
                            wire:model="daysSincePreviousCleaning" placeholder="Например, 7" @disabled($saved)
                            class="min-h-[52px] w-full rounded-[17px] border border-[#E0E6ED] bg-[#F7F9FB] px-4 text-[14px] text-[#253854] outline-none placeholder:text-[#A1ADBA] focus:border-[#3181BB] focus:ring-2 focus:ring-[#3181BB]/20 disabled:opacity-60">
                        @error('daysSincePreviousCleaning') <p role="alert" class="mt-2 text-[12px] text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="flex items-center gap-2 border-t border-[#EEF1F5] pt-3 text-[12px] text-[#77859A]">
                    <x-heroicon-o-user-circle class="size-4" />
                    <span>Проверяет: <span class="font-semibold text-[#334562]">{{ auth()->user()->name ?: auth()->user()->telegram_username ?: 'Не указано' }}</span></span>
                </div>
            </div>
        </section>

        @unless($saved)
            @php
                $completion = $this->completion;
            @endphp
            <section class="rounded-[28px] bg-white p-5 shadow-[0_3px_22px_rgba(25,40,65,0.04)] sm:p-6" aria-label="Прогресс проверки">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <p class="text-[11px] font-bold uppercase tracking-[0.12em] text-[#69788F]">Шаг 02</p>
                        <h2 class="mt-1 text-[19px] font-bold text-[#1D2C47]">Проверка поверхностей</h2>
                    </div>
                    <p class="shrink-0 text-[15px] font-bold tabular-nums text-[#20386B]">{{ $completion['completed'] }} <span class="text-[#97A4B6]">/ {{ $completion['total'] }}</span></p>
                </div>
                <div class="mt-4 h-[9px] overflow-hidden rounded-full bg-[#EAF0F6]" role="progressbar"
                    aria-valuenow="{{ $completion['completed'] }}" aria-valuemin="0" aria-valuemax="{{ $completion['total'] }}"
                    aria-label="Заполнено поверхностей">
                    <div class="h-full rounded-full bg-gradient-to-r from-[#20386B] to-[#3181BB] transition-all duration-300"
                        style="width: {{ (int) round($completion['completed'] * 100 / max(1, $completion['total'])) }}%"></div>
                </div>
                <p class="mt-3 text-[12px] leading-5 text-[#748199]">
                    {{ $completion['completed'] === $completion['total'] ? 'Все поверхности заполнены. Проверьте данные уборки и отправьте анкету.' : 'У каждой поверхности три независимых вопроса. Если загрязнения остались, добавьте фото.' }}
                </p>
            </section>

            <form wire:submit="submit" class="space-y-4">
                @foreach(\App\Services\Antigoccia\AntigocciaAssessment::SURFACES as $surface => $label)
                    @php
                        $dirt = $answers[$surface]['dirt'] ?? null;
                        $removal = $answers[$surface]['removal'] ?? null;
                        $remaining = $answers[$surface]['remaining'] ?? '';
                        $state = $completion['items'][$surface];
                        $stateStyle = match ($state['tone']) {
                            'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                            'danger' => 'bg-red-50 text-red-700 ring-red-100',
                            'progress' => 'bg-blue-50 text-[#2865A3] ring-blue-100',
                            default => 'bg-[#F3F5F8] text-[#7A8799] ring-[#E9EDF2]',
                        };
                    @endphp
                    <section wire:key="antigoccia-{{ $surface }}"
                        class="overflow-hidden rounded-[30px] border {{ $state['needs_photo'] ? 'border-red-100' : 'border-white' }} bg-white shadow-[0_3px_22px_rgba(25,40,65,0.04)]">
                        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-[#EEF1F5] px-5 pb-4 pt-5 sm:px-6">
                            <div class="flex min-w-0 items-start gap-3">
                                <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-[#EAF1F9] text-[15px] font-bold text-[#20386B]">
                                    {{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-[17px] font-bold leading-6 tracking-tight text-[#20304A]">{{ Str::ucfirst(mb_strtolower($label)) }}</h3>
                                    <p class="mt-0.5 text-[12px] text-[#8390A3]">{{ $state['answered'] }} из 3 ответов</p>
                                </div>
                            </div>
                            <span role="status" class="inline-flex min-h-8 items-center rounded-full px-3 py-1 text-[11px] font-bold ring-1 ring-inset {{ $stateStyle }}">
                                {{ $state['label'] }}
                            </span>
                        </div>

                        <div class="space-y-6 px-5 py-5 sm:px-6">
                            <fieldset>
                                <legend class="mb-3 text-[13px] font-semibold leading-5 text-[#344460]">01 · Загрязнение до уборки</legend>
                                <div class="grid grid-cols-3 gap-2">
                                    @foreach([
                                        0 => ['name' => 'Не видно', 'hint' => 'Чисто', 'tone' => 'border-emerald-300 bg-emerald-50 text-emerald-900'],
                                        1 => ['name' => 'Лёгкое', 'hint' => 'Немного', 'tone' => 'border-amber-300 bg-amber-50 text-amber-900'],
                                        2 => ['name' => 'Сильное', 'hint' => 'Заметно', 'tone' => 'border-red-300 bg-red-50 text-red-900'],
                                    ] as $score => $option)
                                        @php($chosen = (string) $dirt === (string) $score)
                                        <button type="button" wire:click="setDirt('{{ $surface }}', {{ $score }})"
                                            aria-pressed="{{ $chosen ? 'true' : 'false' }}"
                                            class="min-h-[90px] rounded-[17px] border-2 px-2 py-3 text-center transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3181BB] {{ $chosen ? $option['tone'].' shadow-sm' : 'border-[#E9EDF2] bg-[#F8F9FB] text-[#506079] hover:border-[#BFCFDF]' }}">
                                            <span class="mx-auto mb-1.5 flex size-7 items-center justify-center rounded-full {{ $chosen ? 'bg-white/85' : 'bg-white' }} text-[14px] font-extrabold">{{ $score }}</span>
                                            <span class="block text-[12px] font-bold leading-4">{{ $option['name'] }}</span>
                                            <span class="mt-1 block text-[10px] leading-4 opacity-75">{{ $option['hint'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                                @error('answers.'.$surface.'.dirt') <p role="alert" class="mt-2 text-[12px] text-red-700">{{ $message }}</p> @enderror
                            </fieldset>

                            <fieldset>
                                <legend class="mb-3 text-[13px] font-semibold leading-5 text-[#344460]">02 · Как удаляются загрязнения?</legend>
                                <div class="grid grid-cols-2 gap-2">
                                    @foreach(\App\Services\Antigoccia\AntigocciaAssessment::REMOVAL_OPTIONS as $value => $option)
                                        @php($chosen = $removal === $value)
                                        <button type="button" wire:click="setRemoval('{{ $surface }}', '{{ $value }}')"
                                            aria-pressed="{{ $chosen ? 'true' : 'false' }}"
                                            class="min-h-[104px] rounded-[18px] border-2 p-3 text-left transition-all focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#3181BB] {{ $chosen ? 'border-[#3181BB] bg-[#EDF4FC] shadow-sm' : 'border-[#E9EDF2] bg-[#F8F9FB] hover:border-[#C3D2E3]' }}">
                                            <span class="flex items-start justify-between gap-2">
                                                <span class="text-[14px] font-bold leading-5 text-[#273A57]">{{ $option['icon'] }} {{ $option['label'] }}</span>
                                                @if($chosen)<x-heroicon-s-check-circle class="size-5 shrink-0 text-[#3181BB]" />@endif
                                            </span>
                                            <span class="mt-1.5 block text-[11px] leading-[1.5] text-[#708099]">{{ $option['description'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                                @error('answers.'.$surface.'.removal') <p role="alert" class="mt-2 text-[12px] text-red-700">{{ $message }}</p> @enderror
                            </fieldset>

                            <fieldset>
                                <legend class="mb-3 text-[13px] font-semibold leading-5 text-[#344460]">03 · Остались загрязнения после уборки?</legend>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="relative flex min-h-[57px] cursor-pointer items-center justify-center gap-2 rounded-[17px] border-2 px-3 text-[13px] font-bold transition-colors focus-within:ring-2 focus-within:ring-[#3181BB] {{ $remaining === 'no' ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-[#E9EDF2] bg-[#F8F9FB] text-[#5D6D84]' }}">
                                        <input type="radio" wire:model.live="answers.{{ $surface }}.remaining" name="remaining-{{ $surface }}" value="no" class="sr-only">
                                        <x-heroicon-o-check-circle class="size-5" />
                                        Нет, чисто
                                    </label>
                                    <label class="relative flex min-h-[57px] cursor-pointer items-center justify-center gap-2 rounded-[17px] border-2 px-3 text-[13px] font-bold transition-colors focus-within:ring-2 focus-within:ring-[#3181BB] {{ $remaining === 'yes' ? 'border-red-300 bg-red-50 text-red-900' : 'border-[#E9EDF2] bg-[#F8F9FB] text-[#5D6D84]' }}">
                                        <input type="radio" wire:model.live="answers.{{ $surface }}.remaining" name="remaining-{{ $surface }}" value="yes" class="sr-only">
                                        <x-heroicon-o-exclamation-circle class="size-5" />
                                        Да, остались
                                    </label>
                                </div>
                                @error('answers.'.$surface.'.remaining') <p role="alert" class="mt-2 text-[12px] text-red-700">{{ $message }}</p> @enderror
                            </fieldset>

                            <div class="rounded-[21px] border {{ $state['needs_photo'] ? 'border-red-200 bg-[#FFF7F6]' : 'border-[#E6ECF2] bg-[#F8FAFC]' }} p-4">
                                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-camera class="size-5 {{ $state['needs_photo'] ? 'text-red-600' : 'text-[#607B9C]' }}" />
                                        <span class="text-[13px] font-bold text-[#30435E]">Фото поверхности</span>
                                    </div>
                                    <span class="text-[11px] font-semibold {{ $state['needs_photo'] ? 'text-red-700' : 'text-[#75869B]' }}">
                                        {{ $state['needs_photo'] ? 'Обязательно' : 'Необязательно' }}
                                    </span>
                                </div>
                                @if($state['needs_photo'] && ! $state['has_photo'])
                                    <p class="mb-3 text-[12px] leading-5 text-red-700">Загрязнения остались — добавьте фото этой поверхности, чтобы сохранить анкету.</p>
                                @endif

                                @if($state['has_photo'])
                                    <div class="mb-3 flex items-center gap-3 rounded-[15px] border border-[#DEE9F2] bg-white p-2.5">
                                        <img src="{{ $photos[$surface]->temporaryUrl() }}" alt="Загруженное фото поверхности {{ $label }}"
                                            class="size-20 shrink-0 rounded-[11px] bg-[#EEF2F6] object-cover">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[13px] font-bold text-[#2B5F51]">Фото добавлено</p>
                                            <p class="mt-1 text-[11px] leading-4 text-[#7B8B9C]">Можно заменить снимок или удалить его до отправки.</p>
                                        </div>
                                        <button type="button" wire:click="removePhoto('{{ $surface }}')" aria-label="Удалить фото {{ $label }}"
                                            class="flex size-10 shrink-0 items-center justify-center rounded-[12px] text-red-700 hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600">
                                            <x-heroicon-o-trash class="size-5" />
                                        </button>
                                    </div>
                                @endif

                                <label for="photo-{{ $surface }}" class="relative flex min-h-[54px] cursor-pointer items-center justify-center gap-2 rounded-[15px] border border-dashed border-[#9FB7D1] bg-white px-3 text-[13px] font-bold text-[#285A91] transition-colors hover:bg-[#EDF4FB] focus-within:ring-2 focus-within:ring-[#3181BB]">
                                    <x-heroicon-o-photo class="size-5" />
                                    {{ $state['has_photo'] ? 'Заменить фотографию' : 'Добавить фотографию' }}
                                    <input id="photo-{{ $surface }}" type="file" accept="image/jpeg,image/png,image/webp"
                                        wire:key="antigoccia-photo-input-{{ $surface }}-{{ $state['has_photo'] ? 'present' : 'empty' }}"
                                        wire:model="photos.{{ $surface }}" aria-label="Фото поверхности {{ $label }}"
                                        class="absolute inset-0 h-full w-full cursor-pointer opacity-0">
                                </label>
                                <p wire:loading wire:target="photos.{{ $surface }}" role="status"
                                    class="mt-2 text-[12px] font-semibold text-[#2865A3]">Загружаем фотографию…</p>
                                @error('photos.'.$surface) <p role="alert" class="mt-2 text-[12px] font-medium text-red-700">{{ $message }}</p> @enderror
                                <p class="mt-2 text-[11px] text-[#8592A5]">JPG, PNG или WebP · до 10 МБ</p>
                            </div>
                        </div>
                    </section>
                @endforeach

                <div class="sticky bottom-3 z-20 rounded-[24px] border border-[#E0E8F0] bg-white/95 p-3 shadow-[0_12px_36px_rgba(26,46,75,0.16)] backdrop-blur-xl sm:p-4">
                    <div class="flex items-center justify-between gap-3 px-1 pb-3">
                        <span class="text-[12px] font-semibold text-[#687991]">Поверхностей заполнено</span>
                        <span class="text-[13px] font-extrabold tabular-nums text-[#20386B]">{{ $completion['completed'] }} / {{ $completion['total'] }}</span>
                    </div>
                    <button type="submit" wire:loading.attr="disabled" wire:target="submit,photos.*"
                        class="flex min-h-[54px] w-full items-center justify-center gap-2 rounded-[17px] bg-gradient-to-r from-[#20386B] to-[#3181BB] px-5 text-[15px] font-bold text-white shadow-sm transition-opacity hover:opacity-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#20386B] disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="submit">Сохранить анкету</span>
                        <span wire:loading wire:target="submit">Сохранение…</span>
                        <x-heroicon-o-arrow-right class="size-5" wire:loading.remove wire:target="submit" />
                    </button>
                </div>
            </form>
        @endunless

        <section class="overflow-hidden rounded-[29px] bg-white shadow-[0_3px_22px_rgba(25,40,65,0.04)]">
            <button type="button" wire:click="$toggle('showMyReports')"
                aria-expanded="{{ $showMyReports ? 'true' : 'false' }}"
                class="flex min-h-[81px] w-full items-center justify-between gap-3 px-5 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#3181BB] sm:px-6">
                <span class="flex items-center gap-3">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-[#F0F4FA] text-[#315E93]">
                        <x-heroicon-o-clock class="size-5" />
                    </span>
                    <span>
                        <span class="block text-[16px] font-bold text-[#243550]">Мои ответы</span>
                        <span class="mt-1 block text-[12px] text-[#8995A6]">История отправленных анкет</span>
                    </span>
                </span>
                <span class="flex items-center gap-1.5 text-[12px] font-semibold text-[#37699C]">
                    {{ $showMyReports ? 'Скрыть' : 'Открыть' }}
                    <x-heroicon-o-chevron-down class="size-4 transition-transform {{ $showMyReports ? 'rotate-180' : '' }}" />
                </span>
            </button>
            @if($showMyReports)
                <div class="space-y-2 border-t border-[#EEF1F5] px-4 py-4 sm:px-6">
                    @forelse($this->myReports as $report)
                        <article wire:key="antigoccia-own-report-{{ $report->id }}" class="rounded-[20px] border border-[#E8EDF2] bg-[#F8FAFC] p-3.5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-[13px] font-bold text-[#283B56]">{{ $report->apartment?->name ?? 'Квартира' }}</p>
                                    <p class="mt-1 text-[11px] text-[#8795A8]">{{ $report->cleaning_date->format('d.m.Y') }}</p>
                                    <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $report->remaining ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800' }}">
                                        {{ $report->remaining ? 'Есть остатки' : 'Без остатков' }}
                                    </span>
                                </div>
                                <button type="button" wire:click="requestDelete({{ $report->id }})"
                                    class="min-h-10 shrink-0 rounded-full border border-[#E6EAF0] bg-white px-3.5 text-[12px] font-bold text-[#B44747] hover:bg-red-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-red-600">
                                    Удалить
                                </button>
                            </div>
                            @if($deleteCandidateId === $report->id)
                                <div class="mt-3 rounded-[15px] border border-red-200 bg-red-50 p-3.5">
                                    <p class="text-[13px] font-bold text-red-900">Удалить эту анкету?</p>
                                    <p class="mt-1 text-[12px] leading-5 text-red-800">Ответ и прикреплённые фотографии будут удалены.</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" wire:click="deleteMyReport" wire:loading.attr="disabled" wire:target="deleteMyReport"
                                            class="min-h-11 rounded-[13px] bg-red-700 px-4 text-[13px] font-bold text-white disabled:opacity-50">Да, удалить</button>
                                        <button type="button" wire:click="cancelDelete"
                                            class="min-h-11 rounded-[13px] bg-white px-4 text-[13px] font-semibold text-[#344460]">Отмена</button>
                                    </div>
                                </div>
                            @endif
                        </article>
                    @empty
                        <div class="py-8 text-center">
                            <x-heroicon-o-clipboard-document-check class="mx-auto size-9 text-[#A8B5C5]" />
                            <p class="mt-3 text-[13px] font-semibold text-[#677790]">Анкет пока нет</p>
                            <p class="mt-1 text-[12px] text-[#9AA6B4]">Первая сохранённая проверка появится здесь.</p>
                        </div>
                    @endforelse
                    @if($this->myReports->hasPages())
                        <div class="pt-3">{{ $this->myReports->links() }}</div>
                    @endif
                </div>
            @endif
        </section>
    </main>
</div>
