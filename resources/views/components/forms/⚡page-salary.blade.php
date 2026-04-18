<?php

use App\Models\SalaryQuestion;
use App\Services\Forms\StaffFormTelegramService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public string $type = '';
    public string $comment = '';
    public array $attachments = [];

    public bool $successSheetOpen = false;
    public ?string $successMessage = null;

    protected function toast(
        string $type,
        string $title,
        string $message = '',
        int $duration = 3500,
    ): void {
        $this->dispatch(
            'toast',
            type: $type,
            title: $title,
            message: $message,
            duration: $duration,
        );
    }

    public function removeAttachment(int $index): void
    {
        if (! isset($this->attachments[$index])) {
            return;
        }

        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    public function resetForm(): void
    {
        $this->type = '';
        $this->comment = '';
        $this->attachments = [];

        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function closeSuccessSheet(): void
    {
        $this->successSheetOpen = false;
        $this->successMessage = null;
    }

    public function submit(StaffFormTelegramService $telegram): void
    {
        $this->validate([
            'type' => ['required', 'string', 'max:255'],
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
            'attachments.*' => ['nullable', 'file', 'max:10240'],
        ], [
            'type.required' => 'Выберите тип вопроса.',
            'comment.required' => 'Напишите комментарий.',
            'comment.min' => 'Комментарий слишком короткий.',
            'comment.max' => 'Максимум 2000 символов.',
            'attachments.*.max' => 'Один файл может быть максимум 10 МБ.',
        ]);

        try {
            $storedAttachments = [];

            foreach ($this->attachments as $file) {
                $storedAttachments[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $file->store('staff-forms/salary', 'public'),
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];
            }

            $record = SalaryQuestion::create([
                'user_id' => Auth::id(),
                'type' => trim($this->type),
                'comment' => trim($this->comment),
                'attachments' => $storedAttachments,
                'status' => 'pending',
            ]);

            try {
                $telegram->sendSalaryQuestion($record);
            } catch (\Throwable $e) {
                Log::error('Salary question telegram failed but record saved', [
                    'record_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->resetForm();

            $this->successMessage = 'Ваш вопрос по зарплате отправлен.';
            $this->successSheetOpen = true;
        } catch (\Throwable $e) {
            Log::error('Salary question submit error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            $this->addError('form', 'Не получилось отправить форму. Попробуйте ещё раз.');

            $this->toast(
                'error',
                'Ошибка отправки',
                'Попробуйте ещё раз через пару минут',
                5000
            );
        }
    }
};

?>

@push('meta')
<title>Вопрос по зарплате • Tris Service Academy</title>
<meta name="description" content="">
<meta name="keywords" content="">
@endpush

<div
    x-data="{
        lastScroll: 0,
        buttonsHidden: false,
        init() {
            const container = this.$refs.scrollArea;

            if (!container) return;

            this.lastScroll = container.scrollTop;

            container.addEventListener('scroll', () => {
                const current = container.scrollTop;

                if (current <= 8) {
                    this.buttonsHidden = false;
                    this.lastScroll = current;
                    return;
                }

                if (current > this.lastScroll + 6) {
                    this.buttonsHidden = true;
                } else if (current < this.lastScroll - 6) {
                    this.buttonsHidden = false;
                }

                this.lastScroll = current;
            }, { passive: true });
        }
    }"
    class="flex h-full min-h-0 flex-col bg-[#F4F7FB]"
>
    <form wire:submit="submit" class="flex h-full min-h-0 flex-col">
        <div x-ref="scrollArea" class="flex-1 min-h-0 overflow-y-auto">
            <div class="min-h-full rounded-t-[38px] bg-white">
                <div class="p-[20px]">
                    <div class="mb-[24px]">
                        <h1 class="mb-[10px] text-[22px] font-semibold tracking-[-0.02em] text-[#111111]">
                            Вопрос по зарплате
                        </h1>

                        <p class="text-[15px] leading-[1.5] text-black/55">
                            Выберите тип вопроса и коротко опишите ситуацию.
                        </p>
                    </div>

                    <div class="space-y-[18px]">
                        <div>
                            <h2 class="mb-[14px] text-[16px] font-semibold text-[#213259]">
                                Тип вопроса
                            </h2>

                            <div class="grid grid-cols-1 gap-[10px]">
                                @foreach ([
                                    'Не пришла зарплата',
                                    'Не пришёл аванс',
                                    'Неправильная сумма',
                                    'Вопрос по бонусу / премии',
                                    'Вопрос по удержанию',
                                    'Другое',
                                ] as $option)
                                    <button
                                        type="button"
                                        wire:click="$set('type', '{{ $option }}')"
                                        class="rounded-[22px] border px-[16px] py-[14px] text-left transition duration-200 {{ $type === $option ? 'border-[#B8D1E6] bg-[#F7FBFF] shadow-[0_10px_24px_rgba(49,129,187,0.10)]' : 'border-[#D9E4EC] bg-[#EAF1F6]' }}"
                                    >
                                        <span class="text-[15px] font-medium text-[#213259]">
                                            {{ $option }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>

                            @error('type')
                                <div class="mt-[8px] px-[4px] text-[15px] text-[#D92D20]">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>

                        <div>
                            <h2 class="mb-[14px] text-[16px] font-semibold text-[#213259]">
                                Комментарий
                            </h2>

                            <textarea
                                wire:model.live.debounce.400ms="comment"
                                rows="6"
                                maxlength="2000"
                                placeholder="Например: за прошлую неделю пришла сумма меньше, чем ожидал"
                                class="w-full rounded-[23px] border border-[#D9E4EC] bg-[#EAF1F6] px-[20px] py-[15px] text-[16px] text-[#213259] placeholder:text-[16px] placeholder:text-[#6F8096] outline-none transition duration-200 focus:border-[#9FB4C9] focus:bg-[#F4F8FB] focus:ring-0"
                            ></textarea>

                            <div class="mt-[8px] flex items-center justify-between gap-[12px] px-[4px]">
                                @error('comment')
                                    <div class="text-[15px] text-[#D92D20]">
                                        {{ $message }}
                                    </div>
                                @else
                                    <div class="text-[13px] text-[#6F8096]">
                                        Чем понятнее описание, тем быстрее вам ответят.
                                    </div>
                                @enderror

                                <div class="shrink-0 text-[13px] text-[#6F8096]">
                                    {{ mb_strlen($comment) }}/2000
                                </div>
                            </div>
                        </div>

                        <div>
                            <h2 class="mb-[14px] text-[16px] font-semibold text-[#213259]">
                                Фото / скрин
                                <span class="font-normal text-black/45">(необязательно)</span>
                            </h2>

                            <label class="flex cursor-pointer items-center justify-center rounded-[24px] border border-dashed border-[#C8D6E3] bg-[#F8FBFD] px-[18px] py-[22px] text-center transition duration-200 hover:bg-white">
                                <input
                                    type="file"
                                    wire:model="attachments"
                                    multiple
                                    class="hidden"
                                >

                                <div>
                                    <div class="text-[24px]">📎</div>
                                    <div class="mt-[8px] text-[15px] font-medium text-[#213259]">
                                        Добавить файлы
                                    </div>
                                    <div class="mt-[4px] text-[13px] text-[#6F8096]">
                                        Фото, скриншоты, документы
                                    </div>
                                </div>
                            </label>

                            @error('attachments.*')
                                <div class="mt-[8px] px-[4px] text-[15px] text-[#D92D20]">
                                    {{ $message }}
                                </div>
                            @enderror

                            @if (! empty($attachments))
                                <div class="mt-[12px] space-y-[8px]">
                                    @foreach ($attachments as $index => $file)
                                        <div class="flex items-center justify-between gap-[10px] rounded-[18px] bg-[#EEF4F8] px-[14px] py-[12px]">
                                            <div class="min-w-0">
                                                <div class="truncate text-[14px] font-medium text-[#213259]">
                                                    {{ $file->getClientOriginalName() }}
                                                </div>
                                            </div>

                                            <button
                                                type="button"
                                                wire:click="removeAttachment({{ $index }})"
                                                class="shrink-0 rounded-full bg-white px-[10px] py-[6px] text-[12px] text-[#213259]"
                                            >
                                                Убрать
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @error('form')
                            <div class="rounded-[23px] bg-[#FDF2F2] px-[16px] py-[14px] text-[15px] text-[#9B1C1C]">
                                ⚠️ {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div
            :class="buttonsHidden ? 'translate-y-full opacity-0 pointer-events-none' : 'translate-y-0 opacity-100'"
            class="shrink-0 border-t border-[#E3EAF0] bg-white p-5 transition-all duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
        >
            <div class="grid grid-cols-3 gap-[10px]">
                <div class="col-span-1">
                    <x-ui.button
                        type="button"
                        variant="secondary"
                        wire:click="resetForm"
                    >
                        Сбросить
                    </x-ui.button>
                </div>

                <div class="col-span-2">
                    <x-ui.button
                        type="submit"
                        variant="primary"
                        wire:loading.attr="disabled"
                        wire:target="submit,attachments"
                    >
                        <span wire:loading.remove wire:target="submit,attachments">
                            Отправить
                        </span>

                        <span wire:loading wire:target="submit,attachments">
                            Отправляем...
                        </span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    </form>

    <div x-data="{ sheetOpen: @entangle('successSheetOpen').live }">
        <x-ui.bottom-sheet x-model="sheetOpen">
            <div class="p-5 text-center">
                <img
                    class="mt-[28px] h-[135px] w-full object-contain"
                    src="{{ asset('images/success.webp') }}"
                    alt="success"
                >

                <h2 class="mt-[28px] text-[22px] font-semibold tracking-[-0.02em] text-[#111111]">
                    Готово
                </h2>

                <p class="pt-[18px] text-[15px] leading-[1.5] text-black/55">
                    {{ $successMessage }}
                </p>

                <div class="flex gap-[10px] pt-[32px]">
                    <x-ui.button
                        variant="secondary"
                        href="{{ route('page-profile.applications') }}"
                    >
                        К заявкам
                    </x-ui.button>

                    <x-ui.button
                        variant="primary"
                        @click="sheetOpen = false"
                    >
                        Закрыть
                    </x-ui.button>
                </div>
            </div>
        </x-ui.bottom-sheet>
    </div>
</div>