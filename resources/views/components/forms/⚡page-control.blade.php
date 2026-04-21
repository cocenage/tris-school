<?php

use App\Models\Apartment;
use App\Models\Control;
use App\Models\ControlResponse;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

new class extends Component {
    use WithFileUploads;

    public ?Control $control = null;

    public array $rooms = [];
    public array $answers = [];
    public array $media = [];
    public array $mediaQueue = [];

    public int $activeRoom = 0;
    public int $mediaLimit = 12;

    public ?int $cleaner_id = null;
    public ?int $apartment_id = null;
    public ?string $cleaning_date = null;
    public ?string $inspection_date = null;
    public bool $is_assigned = false;
    public string $previous_cleaner = '';
    public string $comment = '';

    public bool $success = false;

    public function mount(): void
    {
        $this->cleaning_date = now()->toDateString();
        $this->inspection_date = now()->toDateString();

        $this->control = Control::query()
            ->where('is_active', true)
            ->latest()
            ->first();

        abort_if(! $this->control, 404);

        $this->rooms = is_array($this->control->main) ? array_values($this->control->main) : [];

        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                $this->answers[$roomIndex][$questionIndex] = [
                    'selected' => '',
                    'custom' => '',
                    'media' => [],
                ];
            }
        }
    }

    public function getPeopleProperty()
    {
        return User::query()
            ->whereIn('role', ['cleaner', 'supervisor'])
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

    public function getApartmentsProperty()
    {
        return Apartment::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function updatedMedia($value, string $name): void
    {
        $parts = explode('.', $name);

        if (count($parts) !== 3) {
            return;
        }

        [, $roomIndex, $questionIndex] = $parts;

        $roomIndex = (int) $roomIndex;
        $questionIndex = (int) $questionIndex;

        $current = $this->mediaQueue[$roomIndex][$questionIndex] ?? [];
        $incoming = $this->media[$roomIndex][$questionIndex] ?? [];

        $merged = array_values(array_filter(array_merge($current, $incoming)));

        if (count($merged) > $this->mediaLimit) {
            $merged = array_slice($merged, 0, $this->mediaLimit);
            $this->addError("mediaQueue.$roomIndex.$questionIndex", "Максимум {$this->mediaLimit} файлов");
        }

        $this->mediaQueue[$roomIndex][$questionIndex] = $merged;
        $this->media[$roomIndex][$questionIndex] = [];
    }

    public function removeQueuedMedia(int $roomIndex, int $questionIndex, int $fileIndex): void
    {
        unset($this->mediaQueue[$roomIndex][$questionIndex][$fileIndex]);

        $this->mediaQueue[$roomIndex][$questionIndex] = array_values(
            $this->mediaQueue[$roomIndex][$questionIndex] ?? []
        );
    }

    public function goToRoom(int $roomIndex): void
    {
        if (! isset($this->rooms[$roomIndex])) {
            return;
        }

        $this->activeRoom = $roomIndex;
    }

    public function nextRoom(): void
    {
        if ($this->activeRoom < count($this->rooms) - 1) {
            $this->activeRoom++;
        }
    }

    public function prevRoom(): void
    {
        if ($this->activeRoom > 0) {
            $this->activeRoom--;
        }
    }

    public function getRoomStatus(int $roomIndex): string
    {
        $room = $this->rooms[$roomIndex] ?? null;

        if (! $room) {
            return 'empty';
        }

        $hasErrors = false;
        $hasSomething = false;
        $allRequiredFilled = true;

        foreach (($room['items'] ?? []) as $questionIndex => $question) {
            $answer = $this->answers[$roomIndex][$questionIndex] ?? [];
            $selected = trim((string) ($answer['selected'] ?? ''));
            $custom = trim((string) ($answer['custom'] ?? ''));
            $files = $this->mediaQueue[$roomIndex][$questionIndex] ?? [];

            $type = (string) ($question['answer_type'] ?? 'options');
            $optional = (bool) (($room['is_optional'] ?? false) || ($question['is_optional'] ?? false));

            $filled = match ($type) {
                'text' => $custom !== '',
                'both' => $selected !== '' || $custom !== '',
                default => $selected !== '',
            };

            if ($filled || ! empty($files)) {
                $hasSomething = true;
            }

            if (! $optional && ! $filled) {
                $allRequiredFilled = false;
            }

            if (
                $this->getErrorBag()->has("answers.$roomIndex.$questionIndex")
                || $this->getErrorBag()->has("mediaQueue.$roomIndex.$questionIndex")
            ) {
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            return 'error';
        }

        if ($allRequiredFilled && $hasSomething) {
            return 'done';
        }

        if ($hasSomething) {
            return 'partial';
        }

        return 'empty';
    }

    protected function validateMediaFiles(): void
    {
        foreach ($this->mediaQueue as $roomIndex => $questions) {
            foreach ($questions as $questionIndex => $files) {
                if (count($files) > $this->mediaLimit) {
                    $this->addError("mediaQueue.$roomIndex.$questionIndex", "Максимум {$this->mediaLimit} файлов");
                }

                foreach ($files as $fileIndex => $file) {
                    if (! $file instanceof TemporaryUploadedFile) {
                        continue;
                    }

                    if ($file->getSize() > 30 * 1024 * 1024) {
                        $this->addError("mediaQueue.$roomIndex.$questionIndex.$fileIndex", 'Файл слишком большой');
                    }
                }
            }
        }
    }

    protected function validateRooms(): void
    {
        foreach ($this->rooms as $roomIndex => $room) {
            foreach (($room['items'] ?? []) as $questionIndex => $question) {
                $optional = (bool) (($room['is_optional'] ?? false) || ($question['is_optional'] ?? false));

                if ($optional) {
                    continue;
                }

                $type = (string) ($question['answer_type'] ?? 'options');
                $selected = trim((string) data_get($this->answers, "$roomIndex.$questionIndex.selected", ''));
                $custom = trim((string) data_get($this->answers, "$roomIndex.$questionIndex.custom", ''));

                $filled = match ($type) {
                    'text' => $custom !== '',
                    'both' => $selected !== '' || $custom !== '',
                    default => $selected !== '',
                };

                if (! $filled) {
                    $this->addError("answers.$roomIndex.$questionIndex", 'Ответьте на вопрос');
                }
            }
        }
    }

    public function submit(): void
    {
        $this->resetErrorBag();

        $this->validate([
            'cleaner_id' => ['required', 'integer', 'exists:users,id'],
            'apartment_id' => ['required', 'integer', 'exists:apartments,id'],
            'cleaning_date' => ['required', 'date'],
            'inspection_date' => ['required', 'date'],
            'previous_cleaner' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'cleaner_id.required' => 'Выберите человека',
            'apartment_id.required' => 'Выберите квартиру',
            'cleaning_date.required' => 'Укажите дату уборки',
            'inspection_date.required' => 'Укажите дату проверки',
        ]);

        $this->validateRooms();
        $this->validateMediaFiles();

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        foreach ($this->mediaQueue as $roomIndex => $questions) {
            foreach ($questions as $questionIndex => $files) {
                $stored = [];

                foreach ($files as $file) {
                    if (! $file instanceof TemporaryUploadedFile) {
                        continue;
                    }

                    $path = $file->store('controls/responses', 'public');

                    $stored[] = [
                        'path' => $path,
                        'url' => Storage::disk('public')->url($path),
                        'original_name' => $file->getClientOriginalName(),
                        'mime' => $file->getMimeType(),
                        'type' => str_starts_with((string) $file->getMimeType(), 'video/') ? 'video' : 'image',
                    ];
                }

                $this->answers[$roomIndex][$questionIndex]['media'] = $stored;
            }
        }

        ControlResponse::create([
            'control_id' => $this->control->id,
            'user_id' => $this->cleaner_id,
            'supervisor_id' => Auth::id(),
            'apartment' => (string) Apartment::query()->whereKey($this->apartment_id)->value('name'),
            'is_assigned' => $this->is_assigned,
            'previous_cleaner' => $this->previous_cleaner,
            'cleaning_date' => $this->cleaning_date,
            'inspection_date' => $this->inspection_date,
            'responses' => $this->answers,
            'schema_snapshot' => $this->rooms,
            'supervisor_comment' => $this->comment,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $this->success = true;
    }
};
?>

@push('meta')
    @if ($control)
        <title>{{ $control->name }} • Контроль</title>
        <meta name="description" content="Контроль качества: {{ $control->name }}.">
    @else
        <title>Контроль</title>
        <meta name="description" content="Чек-листы и контроль качества.">
    @endif
@endpush

<section class="mx-[15px] rounded-[32px] bg-white">
    <div class="mx-auto max-w-[760px] px-[15px] py-[16px] md:py-[24px]">
        <div class="mb-6 text-center">
            <h1 class="text-[20px] font-semibold text-[#111827]">
                {{ $control?->name ?? 'Контроль' }}
            </h1>
        </div>

        @if($success)
            <div class="rounded-[20px] border border-green-200 bg-green-50 px-5 py-4 text-green-700">
                Контроль успешно отправлен.
            </div>
        @else
            <form wire:submit.prevent="submit" class="space-y-6">
                @if ($errors->any())
                    <div class="rounded-[20px] border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                        Заполните обязательные поля.
                    </div>
                @endif

                <div class="space-y-4 rounded-[24px] border border-[#E5E7EB] bg-[#F9FAFB] p-4">
                    <div>
                        <div class="mb-2 text-sm font-medium text-[#111827]">
                            Кого проверили *
                        </div>

                        <select
                            wire:model.live="cleaner_id"
                            class="block h-11 w-full rounded-[14px] border border-[#D1D5DB] bg-white px-4 text-sm focus:border-[#94A3B8] focus:outline-none"
                        >
                            <option value="">Выберите человека</option>

                            @foreach($this->people as $person)
                                <option value="{{ $person->id }}">
                                    {{ $person->name }} ({{ $person->role === 'cleaner' ? 'Клинер' : 'Супервайзер' }})
                                </option>
                            @endforeach
                        </select>

                        @error('cleaner_id')
                            <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <div class="mb-2 text-sm font-medium text-[#111827]">
                            Квартира *
                        </div>

                        <select
                            wire:model.live="apartment_id"
                            class="block h-11 w-full rounded-[14px] border border-[#D1D5DB] bg-white px-4 text-sm focus:border-[#94A3B8] focus:outline-none"
                        >
                            <option value="">Выберите квартиру</option>

                            @foreach($this->apartments as $apartment)
                                <option value="{{ $apartment->id }}">
                                    {{ $apartment->name }}
                                </option>
                            @endforeach
                        </select>

                        @error('apartment_id')
                            <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <div class="mb-2 text-sm font-medium text-[#111827]">
                                Дата уборки *
                            </div>

                            <input
                                type="date"
                                wire:model.live="cleaning_date"
                                class="block h-11 w-full rounded-[14px] border border-[#D1D5DB] bg-white px-4 text-sm focus:border-[#94A3B8] focus:outline-none"
                            >

                            @error('cleaning_date')
                                <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>

                        <div>
                            <div class="mb-2 text-sm font-medium text-[#111827]">
                                Дата проверки *
                            </div>

                            <input
                                type="date"
                                wire:model.live="inspection_date"
                                class="block h-11 w-full rounded-[14px] border border-[#D1D5DB] bg-white px-4 text-sm focus:border-[#94A3B8] focus:outline-none"
                            >

                            @error('inspection_date')
                                <div class="mt-2 text-sm text-red-600">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <label class="flex items-center gap-3">
                        <input
                            type="checkbox"
                            wire:model.live="is_assigned"
                            class="h-4 w-4 rounded border-[#D1D5DB]"
                        >
                        <span class="text-sm text-[#111827]">
                            Человек закреплён за этой квартирой
                        </span>
                    </label>

                    <div>
                        <div class="mb-2 text-sm font-medium text-[#111827]">
                            Кто делал уборку до этого
                        </div>

                        <input
                            type="text"
                            wire:model.live.debounce.300ms="previous_cleaner"
                            placeholder="Имя / примечание"
                            class="block h-11 w-full rounded-[14px] border border-[#D1D5DB] bg-white px-4 text-sm focus:border-[#94A3B8] focus:outline-none"
                        >
                    </div>
                </div>

                @if(count($rooms))
                    <div class="sticky top-[10px] z-20 rounded-[20px] border border-[#E5E7EB] bg-white p-3">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div class="text-sm font-medium text-[#111827]">
                                Комната {{ $activeRoom + 1 }} из {{ count($rooms) }}
                            </div>

                            <div class="text-xs text-[#6B7280]">
                                {{ round((($activeRoom + 1) / max(count($rooms), 1)) * 100) }}%
                            </div>
                        </div>

                        <div class="flex gap-2 overflow-x-auto">
                            @foreach($rooms as $roomIndex => $roomTab)
                                @php
                                    $status = $this->getRoomStatus($roomIndex);

                                    $statusClass = match($status) {
                                        'done' => 'border-green-300 bg-green-50 text-green-700',
                                        'partial' => 'border-blue-300 bg-blue-50 text-blue-700',
                                        'error' => 'border-red-300 bg-red-50 text-red-700',
                                        default => 'border-[#D1D5DB] bg-white text-[#111827]',
                                    };
                                @endphp

                                <button
                                    type="button"
                                    wire:click="goToRoom({{ $roomIndex }})"
                                    class="shrink-0 rounded-full border px-4 py-2 text-sm transition
                                    {{ $activeRoom === $roomIndex ? 'border-[#111827] bg-[#111827] text-white' : $statusClass }}"
                                >
                                    {{ $roomTab['title'] ?? ('Комната ' . ($roomIndex + 1)) }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @php
                        $roomIndex = $activeRoom;
                        $room = $rooms[$roomIndex] ?? null;
                        $roomStatus = $this->getRoomStatus($roomIndex);
                        $roomBorderClass = match($roomStatus) {
                            'done' => 'border-green-300',
                            'partial' => 'border-blue-300',
                            'error' => 'border-red-300',
                            default => 'border-[#E5E7EB]',
                        };
                    @endphp

                    @if($room)
                        <div class="space-y-4">
                            <div class="rounded-[24px] border {{ $roomBorderClass }} bg-[#F9FAFB] p-5">
                                @if(!empty($room['room_image']))
                                    <div class="mb-4">
                                        <img
                                            src="{{ Storage::url($room['room_image']) }}"
                                            alt="{{ $room['title'] ?? 'Комната' }}"
                                            class="h-[180px] w-full rounded-[18px] object-cover"
                                        >
                                    </div>
                                @endif

                                <h2 class="text-[18px] font-semibold text-[#111827]">
                                    {{ $room['title'] ?? 'Комната' }}
                                </h2>

                                @if(!empty($room['description']))
                                    <p class="mt-2 text-sm text-[#6B7280]">
                                        {{ $room['description'] }}
                                    </p>
                                @endif

                                @if(!empty($room['is_optional']))
                                    <div class="mt-2 text-xs text-[#2563EB]">
                                        Необязательная комната
                                    </div>
                                @endif
                            </div>

                            @foreach(($room['items'] ?? []) as $questionIndex => $question)
                                @php
                                    $questionError = $errors->has("answers.$roomIndex.$questionIndex")
                                        || $errors->has("mediaQueue.$roomIndex.$questionIndex");
                                    $opts = $question['answer_options_scored'] ?? [];
                                    $mediaList = $mediaQueue[$roomIndex][$questionIndex] ?? [];
                                @endphp

                                <div class="rounded-[20px] border {{ $questionError ? 'border-red-300' : 'border-[#E5E7EB]' }} bg-white p-4">
                                    <div class="text-[15px] font-medium text-[#111827]">
                                        {{ $question['question'] ?? 'Вопрос' }}

                                        @if(!(($room['is_optional'] ?? false) || ($question['is_optional'] ?? false)))
                                            <span class="text-[#2563EB]">*</span>
                                        @else
                                            <span class="ml-1 text-xs text-[#2563EB]">(необязательно)</span>
                                        @endif
                                    </div>

                                    @error("answers.$roomIndex.$questionIndex")
                                        <div class="mt-3 text-sm text-red-600">
                                            {{ $message }}
                                        </div>
                                    @enderror

                                    <div class="mt-4 space-y-4">
                                        @if(!empty($question['question_image']))
                                            <img
                                                src="{{ Storage::url($question['question_image']) }}"
                                                alt="Иллюстрация к вопросу"
                                                class="max-h-[260px] w-full rounded-[16px] object-cover"
                                            >
                                        @endif

                                        @if(($question['answer_type'] ?? 'options') === 'options')
                                            <div class="space-y-2">
                                                @foreach($opts as $optIndex => $opt)
                                                    <label class="flex cursor-pointer items-center gap-3 rounded-[14px] border border-[#E5E7EB] bg-[#F9FAFB] px-4 py-3">
                                                        <input
                                                            type="radio"
                                                            wire:model.live="answers.{{ $roomIndex }}.{{ $questionIndex }}.selected"
                                                            value="{{ $opt['value'] ?? ('option_' . $optIndex) }}"
                                                            class="h-4 w-4 border-[#D1D5DB]"
                                                        >
                                                        <span class="text-sm text-[#111827]">
                                                            {{ $opt['label'] ?? 'Вариант' }}
                                                        </span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @elseif(($question['answer_type'] ?? 'options') === 'text')
                                            <input
                                                type="text"
                                                wire:model.live.debounce.300ms="answers.{{ $roomIndex }}.{{ $questionIndex }}.custom"
                                                placeholder="{{ $question['custom_answer_label'] ?? 'Введите ответ' }}"
                                                class="block h-11 w-full rounded-[14px] border border-[#D1D5DB] bg-white px-4 text-sm focus:border-[#94A3B8] focus:outline-none"
                                            >
                                        @elseif(($question['answer_type'] ?? 'options') === 'both')
                                            <div class="space-y-3">
                                                <div class="space-y-2">
                                                    @foreach($opts as $optIndex => $opt)
                                                        <label class="flex cursor-pointer items-center gap-3 rounded-[14px] border border-[#E5E7EB] bg-[#F9FAFB] px-4 py-3">
                                                            <input
                                                                type="radio"
                                                                wire:model.live="answers.{{ $roomIndex }}.{{ $questionIndex }}.selected"
                                                                value="{{ $opt['value'] ?? ('option_' . $optIndex) }}"
                                                                class="h-4 w-4 border-[#D1D5DB]"
                                                            >
                                                            <span class="text-sm text-[#111827]">
                                                                {{ $opt['label'] ?? 'Вариант' }}
                                                            </span>
                                                        </label>
                                                    @endforeach
                                                </div>

                                                <input
                                                    type="text"
                                                    wire:model.live.debounce.300ms="answers.{{ $roomIndex }}.{{ $questionIndex }}.custom"
                                                    placeholder="{{ $question['custom_answer_label'] ?? 'Другое' }}"
                                                    class="block h-11 w-full rounded-[14px] border border-[#D1D5DB] bg-white px-4 text-sm focus:border-[#94A3B8] focus:outline-none"
                                                >
                                            </div>
                                        @endif

                                        <div class="rounded-[16px] border border-[#E5E7EB] bg-[#F9FAFB] p-4">
                                            <div class="mb-2 flex items-center justify-between gap-3">
                                                <div class="text-sm text-[#111827]">
                                                    Фото / видео
                                                </div>

                                                <div class="text-xs text-[#6B7280]">
                                                    {{ count($mediaList) }}/{{ $mediaLimit }}
                                                </div>
                                            </div>

                                            <label class="inline-flex cursor-pointer rounded-[12px] border border-[#D1D5DB] bg-white px-4 py-2 text-sm text-[#111827]">
                                                Выбрать файлы
                                                <input
                                                    type="file"
                                                    multiple
                                                    accept="image/*,video/*,.mp4,.mov,.qt,.webm,.m4v,.3gp,.3gpp"
                                                    wire:model="media.{{ $roomIndex }}.{{ $questionIndex }}"
                                                    class="hidden"
                                                >
                                            </label>

                                            @error("mediaQueue.$roomIndex.$questionIndex")
                                                <div class="mt-2 text-sm text-red-600">
                                                    {{ $message }}
                                                </div>
                                            @enderror

                                            @if(!empty($mediaList))
                                                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-3">
                                                    @foreach($mediaList as $i => $file)
                                                        <div class="relative overflow-hidden rounded-[14px] border border-[#E5E7EB] bg-white">
                                                            @if($file instanceof TemporaryUploadedFile)
                                                                @if(str_starts_with((string) $file->getMimeType(), 'image/'))
                                                                    <img
                                                                        src="{{ $file->temporaryUrl() }}"
                                                                        alt="{{ $file->getClientOriginalName() }}"
                                                                        class="h-28 w-full object-cover"
                                                                    >
                                                                @else
                                                                    <div class="flex h-28 items-center justify-center px-3 text-center text-xs text-[#6B7280]">
                                                                        {{ $file->getClientOriginalName() }}
                                                                    </div>
                                                                @endif
                                                            @elseif(is_array($file) && !empty($file['url']))
                                                                @if(($file['type'] ?? null) === 'image')
                                                                    <img
                                                                        src="{{ $file['url'] }}"
                                                                        alt="{{ $file['original_name'] ?? 'Файл' }}"
                                                                        class="h-28 w-full object-cover"
                                                                    >
                                                                @else
                                                                    <video
                                                                        src="{{ $file['url'] }}"
                                                                        class="h-28 w-full object-cover bg-black"
                                                                        controls
                                                                    ></video>
                                                                @endif
                                                            @else
                                                                <div class="flex h-28 items-center justify-center px-3 text-center text-xs text-[#6B7280]">
                                                                    Файл
                                                                </div>
                                                            @endif

                                                            <button
                                                                type="button"
                                                                wire:click="removeQueuedMedia({{ $roomIndex }}, {{ $questionIndex }}, {{ $i }})"
                                                                class="absolute right-2 top-2 flex h-7 w-7 items-center justify-center rounded-full bg-black/60 text-white"
                                                            >
                                                                ✕
                                                            </button>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                wire:click="prevRoom"
                                @disabled($activeRoom === 0)
                                class="h-11 rounded-[14px] border border-[#D1D5DB] bg-white text-sm text-[#111827] disabled:opacity-40"
                            >
                                Назад
                            </button>

                            <button
                                type="button"
                                wire:click="nextRoom"
                                @disabled($activeRoom >= count($rooms) - 1)
                                class="h-11 rounded-[14px] border border-[#D1D5DB] bg-white text-sm text-[#111827] disabled:opacity-40"
                            >
                                Далее
                            </button>
                        </div>
                    @endif
                @endif

                <div>
                    <div class="mb-2 text-sm font-medium text-[#111827]">
                        Комментарий
                    </div>

                    <textarea
                        wire:model.live.debounce.300ms="comment"
                        rows="4"
                        placeholder="Комментарий супервайзера"
                        class="block w-full rounded-[16px] border border-[#D1D5DB] bg-white px-4 py-3 text-sm focus:border-[#94A3B8] focus:outline-none"
                    ></textarea>
                </div>

                <div class="sticky bottom-0 z-20 bg-white py-2">
                    <button
                        type="submit"
                        class="h-12 w-full rounded-[16px] bg-[#111827] text-sm font-medium text-white"
                    >
                        Отправить результат
                    </button>
                </div>
            </form>
        @endif
    </div>
</section>