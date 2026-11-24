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

    public function getMyReportsProperty()
    {
        return AntigocciaReport::query()
            ->with('apartment:id,name')
            ->where('user_id', auth()->id())
            ->latest('created_at')->latest('id')
            ->paginate(10, ['id', 'apartment_id', 'user_id', 'cleaning_date', 'created_at'], 'antigocciaMine');
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
        <h1 class="mb-5 text-[29px] font-semibold">Антикапля</h1>

        <div class="space-y-[12px] rounded-[28px] bg-[#F6F6F6] p-[17px]">
            <label class="block text-[13px] font-semibold" for="antigoccia-apartment-search">Адрес</label>
            @if($this->apartment)
                <div class="flex items-center justify-between gap-3 rounded-[17px] bg-white px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-[15px] font-semibold">{{ $this->apartment->name }}</p>
                        @if($this->apartment->address)
                            <p class="text-[12px] text-black/50">{{ $this->apartment->address }}</p>
                        @endif
                    </div>
                    <button type="button" wire:click="clearApartment" class="shrink-0 rounded-full bg-[#F1F1F1] px-3 py-2 text-[12px] font-semibold">Изменить</button>
                </div>
            @else
                <input id="antigoccia-apartment-search" type="search"
                    wire:model.live.debounce.250ms="apartmentSearch"
                    placeholder="Поиск квартиры или адреса"
                    autocomplete="off" aria-autocomplete="list"
                    aria-controls="antigoccia-apartment-options" aria-expanded="true"
                    role="combobox"
                    class="w-full rounded-[17px] bg-white px-4 py-3 text-[15px]">
                <div id="antigoccia-apartment-options" role="listbox" class="max-h-[220px] space-y-1 overflow-y-auto rounded-[17px] bg-white p-[5px]">
                    @forelse($this->apartmentMatches as $option)
                        <button type="button" role="option" aria-selected="false"
                            wire:key="antigoccia-apartment-option-{{ $option->id }}"
                            wire:click="selectApartment({{ $option->id }})"
                            class="block w-full rounded-[13px] px-3 py-2 text-left hover:bg-[#F6F6F6] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#20386B]">
                            <span class="block text-[14px] font-medium">{{ $option->name }}</span>
                            @if($option->address)<span class="block text-[11px] text-black/50">{{ $option->address }}</span>@endif
                        </button>
                    @empty
                        <p class="px-3 py-3 text-[13px] text-black/50">Ничего не найдено</p>
                    @endforelse
                </div>
            @endif
            @error('apartmentId') <p role="alert" class="text-[12px] text-red-600">{{ $message }}</p> @enderror

            @if($this->apartment)
                @if($this->application)
                    <p class="text-[13px]">Дата обработки: <span class="font-semibold">{{ $this->application->applied_on->format('d.m.Y') }}</span></p>
                @else
                    <label class="block text-[13px] font-semibold" for="antigoccia-coating-date">Дата обработки</label>
                    <input id="antigoccia-coating-date" type="date" wire:model="coatingDate" max="{{ $cleaningDate }}" class="w-full rounded-[17px] bg-white px-4 py-3 text-[15px]">
                    @error('coatingDate') <p role="alert" class="text-[12px] text-red-600">{{ $message }}</p> @enderror
                @endif
            @endif

            <label class="block text-[13px] font-semibold" for="antigoccia-date">Дата уборки</label>
            <input id="antigoccia-date" type="date" wire:model.live="cleaningDate" max="{{ now('Europe/Rome')->toDateString() }}" class="w-full rounded-[17px] bg-white px-4 py-3 text-[15px]">
            @error('cleaningDate') <p role="alert" class="text-[12px] text-red-600">{{ $message }}</p> @enderror

            <label class="block text-[13px] font-semibold" for="antigoccia-last-cleaning">Сколько дней назад была последняя уборка?</label>
            <input id="antigoccia-last-cleaning" type="number" min="0" max="36500" step="1" inputmode="numeric"
                wire:model="daysSincePreviousCleaning" placeholder="Дней"
                class="w-full rounded-[17px] bg-white px-4 py-3 text-[15px]">
            @error('daysSincePreviousCleaning') <p role="alert" class="text-[12px] text-red-600">{{ $message }}</p> @enderror

            <p class="text-[13px]">Клинер: <span class="font-semibold">{{ auth()->user()->name ?: auth()->user()->telegram_username ?: 'Не указано' }}</span></p>
        </div>

        @if($saved)
            <div role="status" class="mt-[18px] rounded-[26px] bg-green-50 p-[18px] text-green-800">
                <p class="font-semibold">Анкета сохранена</p>
                <button type="button" wire:click="newForm" class="mt-4 rounded-full bg-white px-4 py-3 text-[14px] font-semibold">Новая анкета</button>
            </div>
        @else
            <form wire:submit="submit" class="mt-[18px] space-y-[12px]">
                @foreach(\App\Services\Antigoccia\AntigocciaAssessment::SURFACES as $surface => $label)
                    @php
                        $dirt = $answers[$surface]['dirt'] ?? null;
                        $removal = $answers[$surface]['removal'] ?? null;
                        $remaining = $answers[$surface]['remaining'] ?? '';
                    @endphp
                    <section wire:key="antigoccia-{{ $surface }}" class="rounded-[28px] bg-[#F6F6F6] p-[17px]">
                        <h2 class="mb-4 text-[18px] font-semibold">{{ $loop->iteration }}. {{ $label }}</h2>
                        <p class="mb-2 text-[13px] font-medium">Оцените степень загрязнения поверхности ДО уборки:</p>
                        <div class="grid grid-cols-3 gap-[7px]">
                            @foreach([0 => '🟢 0 — Загрязнений не видно.', 1 => '🟡 1 — Лёгкие загрязнения.', 2 => '🔴 2 — Сильные загрязнения.'] as $score => $text)
                                <button type="button" wire:click="setDirt('{{ $surface }}', {{ $score }})"
                                    aria-pressed="{{ (string) $dirt === (string) $score ? 'true' : 'false' }}"
                                    class="min-h-[54px] rounded-[16px] px-2 py-2 text-[12px] font-medium {{ (string) $dirt === (string) $score ? 'bg-[#213259] text-white' : 'bg-white text-black/75' }}">{{ $text }}</button>
                            @endforeach
                        </div>
                        @error('answers.'.$surface.'.dirt') <p role="alert" class="mt-2 text-[12px] text-red-600">{{ $message }}</p> @enderror

                        <p class="mb-2 mt-4 text-[13px] font-medium">Насколько легко удаляются загрязнения влажной чистой салфеткой?</p>
                        <div class="grid grid-cols-2 gap-[7px]">
                            @foreach(\App\Services\Antigoccia\AntigocciaAssessment::REMOVAL_OPTIONS as $value => $option)
                                <button type="button" wire:click="setRemoval('{{ $surface }}', '{{ $value }}')"
                                    aria-pressed="{{ $removal === $value ? 'true' : 'false' }}"
                                    class="min-h-[92px] rounded-[16px] px-3 py-3 text-left text-[12px] {{ $removal === $value ? 'bg-[#213259] text-white' : 'bg-white text-black/75' }}">
                                    <span class="block font-semibold">{{ $option['icon'] }} {{ $option['label'] }}</span>
                                    <span class="mt-1 block text-[11px] leading-[1.45] {{ $removal === $value ? 'text-white/80' : 'text-black/55' }}">{{ $option['description'] }}</span>
                                </button>
                            @endforeach
                        </div>
                        @error('answers.'.$surface.'.removal') <p role="alert" class="mt-2 text-[12px] text-red-600">{{ $message }}</p> @enderror

                        <div class="mt-4 border-t border-black/10 pt-4">
                            <p class="mb-3 text-[14px] font-semibold">Остались загрязнения после уборки?</p>
                            <div class="flex gap-3">
                                <label class="rounded-[15px] bg-white px-4 py-3 text-[14px]">
                                    <input type="radio" wire:model.live="answers.{{ $surface }}.remaining" name="remaining-{{ $surface }}" value="yes" class="mr-1"> Да
                                </label>
                                <label class="rounded-[15px] bg-white px-4 py-3 text-[14px]">
                                    <input type="radio" wire:model.live="answers.{{ $surface }}.remaining" name="remaining-{{ $surface }}" value="no" class="mr-1"> Нет
                                </label>
                            </div>
                            @error('answers.'.$surface.'.remaining') <p role="alert" class="mt-2 text-[12px] text-red-600">{{ $message }}</p> @enderror
                            <label class="mt-3 block text-[13px] font-semibold" for="photo-{{ $surface }}">Фото поверхности {{ $remaining === 'yes' ? '(обязательно)' : '(необязательно)' }}</label>
                            <input id="photo-{{ $surface }}" type="file" accept="image/jpeg,image/png,image/webp" wire:model="photos.{{ $surface }}" class="mt-2 w-full text-[13px]">
                            <p wire:loading wire:target="photos.{{ $surface }}" class="mt-2 text-[12px] text-blue-700">Загрузка…</p>
                            @if(isset($photos[$surface])) <p class="mt-2 text-[12px] text-green-700">Фото загружено</p> @endif
                            @error('photos.'.$surface) <p role="alert" class="mt-2 text-[12px] text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </section>
                @endforeach

                <button type="submit" wire:loading.attr="disabled" wire:target="submit,photos.*"
                    class="w-full rounded-[22px] bg-[#213259] px-5 py-4 text-[16px] font-semibold text-white disabled:opacity-50">
                    <span wire:loading.remove wire:target="submit">Сохранить анкету</span>
                    <span wire:loading wire:target="submit">Сохранение…</span>
                </button>
            </form>
        @endif

        <section class="mt-5 rounded-[25px] bg-[#F6F6F6] p-[17px]">
            <button type="button" wire:click="$toggle('showMyReports')"
                class="flex w-full items-center justify-between text-left text-[15px] font-semibold">
                <span>Мои ответы</span>
                <span class="text-[13px] text-black/50">{{ $showMyReports ? 'Скрыть' : 'Показать' }}</span>
            </button>
            @if($showMyReports)
                <div class="mt-3 space-y-2">
                    @forelse($this->myReports as $report)
                        <div wire:key="antigoccia-own-report-{{ $report->id }}" class="rounded-[16px] bg-white p-3">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-[13px] font-medium">{{ $report->apartment?->name ?? 'Квартира' }}</p>
                                    <p class="text-[12px] text-black/50">{{ $report->cleaning_date->format('d.m.Y') }}</p>
                                </div>
                                <button type="button" wire:click="requestDelete({{ $report->id }})"
                                    class="shrink-0 rounded-[12px] px-3 py-2 text-[12px] font-medium text-red-700">Удалить</button>
                            </div>
                            @if($deleteCandidateId === $report->id)
                                <div class="mt-3 border-t border-black/10 pt-3">
                                    <p class="text-[13px]">Удалить этот ответ и его фотографии?</p>
                                    <div class="mt-3 flex gap-2">
                                        <button type="button" wire:click="deleteMyReport" wire:loading.attr="disabled" wire:target="deleteMyReport"
                                            class="rounded-[12px] bg-red-700 px-4 py-2 text-[13px] font-semibold text-white disabled:opacity-50">Да, удалить</button>
                                        <button type="button" wire:click="cancelDelete"
                                            class="rounded-[12px] bg-[#F6F6F6] px-4 py-2 text-[13px]">Отмена</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-[13px] text-black/50">Ответов пока нет</p>
                    @endforelse
                    @if($this->myReports->hasPages())
                        <div class="mt-3">{{ $this->myReports->links() }}</div>
                    @endif
                </div>
            @endif
        </section>
    </div>
</div>
