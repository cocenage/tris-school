<?php

use App\Models\ScheduleQuestion;
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

    protected function buildSuccessMessage(): string
    {
        $now = now()->setTimezone(config('app.timezone'));

        $start = $now->copy()->setTime(10, 0);
        $end = $now->copy()->setTime(18, 0);

        if ($now->between($start, $end)) {
            return 'Ответ ожидайте сегодня с 10:00 до 18:00';
        }

        if ($now->greaterThan($end)) {
            return 'Мы получили её после окончания рабочего дня. Ответ ожидайте завтра с 10:00 до 18:00';
        }

        return 'Ответ ожидайте сегодня с 10:00 до 18:00';
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
                    'path' => $file->store('staff-forms/schedule', 'public'),
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];
            }

            $record = ScheduleQuestion::create([
                'user_id' => Auth::id(),
                'type' => trim($this->type),
                'comment' => trim($this->comment),
                'attachments' => $storedAttachments,
                'status' => 'pending',
            ]);

            try {
                $telegram->sendScheduleQuestion($record);
            } catch (\Throwable $e) {
                Log::error('Schedule question telegram failed but record saved', [
                    'record_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->resetForm();

            $this->successMessage = $this->buildSuccessMessage();
            $this->successSheetOpen = true;
        } catch (\Throwable $e) {
            Log::error('Schedule question submit error', [
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

<x-slot:header>
    <div class="w-full h-[70px] flex items-center justify-between px-[15px]">
        <button
            type="button"
            onclick="history.back()"
            class="group flex h-[36px] w-[36px] items-center justify-center rounded-full text-[#213259] transition-all duration-200 hover:bg-[#213259]/6 active:bg-[#213259]/10"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2] transition-all duration-200 group-hover:-translate-x-[1px] group-hover:text-[#2D6494]" />
        </button>

        <span class="flex items-center justify-center text-[18px] leading-none">
            Вопрос по графику работы
        </span>

        <button
            type="button"
            class="flex h-[36px] w-[36px] items-center justify-center rounded-full text-[#213259] transition-all duration-200 hover:bg-[#213259]/6 hover:text-[#2D6494] active:bg-[#213259]/10"
        >
            <x-heroicon-o-magnifying-glass class="h-[20px] w-[20px] stroke-[2]" />
        </button>
    </div>
</x-slot:header>

<div
    x-data="{
        lastScrollTop: 0,
        buttonsHidden: false,
        nearBottom: false,

        init() {
            const el = this.$refs.scrollArea;
            if (!el) return;

            const onScroll = () => {
                const current = el.scrollTop;
                const maxScroll = el.scrollHeight - el.clientHeight;

                this.nearBottom = current >= (maxScroll - 140);

                if (this.nearBottom) {
                    this.buttonsHidden = false;
                    this.lastScrollTop = current;
                    return;
                }

                if (current <= 8) {
                    this.buttonsHidden = false;
                    this.lastScrollTop = current;
                    return;
                }

                if (current > this.lastScrollTop + 8) {
                    this.buttonsHidden = true;
                } else if (current < this.lastScrollTop - 8) {
                    this.buttonsHidden = false;
                }

                this.lastScrollTop = current;
            };

            onScroll();
            el.addEventListener('scroll', onScroll, { passive: true });
        }
    }"
    class="flex h-full min-h-0 flex-col bg-[#F4F7FB]"
>
    <form wire:submit="submit" class="flex h-full min-h-0 flex-col">
        <div
            x-ref="scrollArea"
            class="flex-1 min-h-0 overflow-y-auto"
        >
            <div class="min-h-full rounded-t-[38px] bg-white">
                <div class="p-[20px] pb-[82px]">
                    <div class="mb-[24px] relative z-20" x-data="{ open: false }">
                        <h2 class="mb-[14px] text-[16px] font-medium text-[#213259]">
                            Что случилось?
                        </h2>

                        <button
                            type="button"
                            @click="open = !open"
                            class="w-full flex items-center justify-between rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] px-[18px] py-[14px] text-left transition duration-200 hover:bg-white"
                        >
                            <span class="text-[15px] text-[#213259]">
                                {{ $type ?: 'Выберите тип вопроса' }}
                            </span>

                            <div
                                class="transition-transform duration-200"
                                :class="open ? 'rotate-180' : ''"
                            >
                                <x-heroicon-o-chevron-down class="w-[18px] h-[18px] text-[#213259]" />
                            </div>
                        </button>

                        <div
                            x-show="open"
                            x-transition.origin.top
                            @click.outside="open = false"
                            class="absolute left-0 right-0 top-full mt-[8px] overflow-hidden rounded-[20px] border border-[#E7E7E7] bg-white shadow-[0_10px_24px_rgba(33,50,89,0.08)]"
                            style="display: none;"
                        >
                            @foreach ([
                                'Не могу выйти',
                                'Хочу поменять смену',
                                'Вопрос по расписанию',
                                'Хочу больше смен',
                                'Хочу меньше смен',
                                'Другое',
                            ] as $option)
                                <button
                                    type="button"
                                    wire:click="$set('type', '{{ $option }}')"
                                    @click="open = false"
                                    class="w-full px-[18px] py-[14px] text-left text-[15px] text-[#213259] transition duration-150 {{ $type === $option ? 'bg-[#F4F7FB] font-medium' : 'hover:bg-[#F8F8F8]' }}"
                                >
                                    {{ $option }}
                                </button>
                            @endforeach
                        </div>

                        @error('type')
                            <div class="mt-[8px] px-[4px] text-[15px] text-[#D92D20]">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    <div class="mb-[18px]">
                        <h2 class="mb-[14px] text-[16px] font-medium text-[#213259]">
                            Расскажите подробней
                        </h2>

                        <textarea
                            wire:model.live.debounce.400ms="comment"
                            rows="6"
                            maxlength="2000"
                            placeholder="Например: не смогу выйти в воскресенье вечером, нужен перенос и тд.."
                            class="w-full rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] px-[20px] py-[15px] text-[16px] placeholder:text-black/35 outline-none transition focus:border-[#D6D6D6] focus:bg-white focus:ring-0"
                        ></textarea>

                    </div>

                    <div class="mb-[8px]">
                        <h2 class="mb-[14px] text-[16px] font-medium text-[#213259]">
                            Добавьте фото
                 
                        </h2>

                <label class="block cursor-pointer rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] px-[18px] py-[16px] transition duration-200 hover:bg-white">
    <input
        type="file"
        wire:model="attachments"
        multiple
        accept="image/*"
        class="hidden"
    >

    <div class="flex items-center gap-[12px]">

        <div class="min-w-0 flex-1">
            <div class="text-[15px] font-medium text-[#213259]">
                Выбрать фото
            </div>

            <div class="mt-[2px] text-[13px] text-black/40">
                Можно добавить одну или несколько фотографий
            </div>
        </div>
    </div>
</label>

                        <div
                            wire:loading
                            wire:target="attachments"
                            class="mt-[8px] px-[4px] text-[13px] text-black/40"
                        >
                            Загружаем фото...
                        </div>

                        @error('attachments.*')
                            <div class="mt-[8px] px-[4px] text-[15px] text-[#D92D20]">
                                {{ $message }}
                            </div>
                        @enderror

                        @if (! empty($attachments))
                            <div class="mt-[12px] grid grid-cols-3 gap-[8px] sm:grid-cols-4">
                                @foreach ($attachments as $index => $file)
                                    <div class="relative overflow-hidden rounded-[18px] border border-[#E7E7E7] bg-[#F8F8F8]">
                                        @if (str_starts_with((string) $file->getMimeType(), 'image/'))
                                            <img
                                                src="{{ $file->temporaryUrl() }}"
                                                alt="{{ $file->getClientOriginalName() }}"
                                                class="h-[96px] w-full object-cover"
                                            >
                                        @else
                                            <div class="flex h-[96px] items-center justify-center px-[10px] text-center text-[12px] text-black/40">
                                                Файл
                                            </div>
                                        @endif

                                        <div class="border-t border-[#E7E7E7] px-[10px] py-[8px]">
                                            <div class="truncate text-[12px] font-medium text-[#213259]">
                                                {{ $file->getClientOriginalName() }}
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            wire:click="removeAttachment({{ $index }})"
                                            class="absolute right-[6px] top-[6px] flex h-[26px] w-[26px] items-center justify-center rounded-full bg-white/95 text-[12px] font-medium text-[#213259] shadow-sm"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @error('form')
                        <div class="mt-[14px] rounded-[23px] bg-[#FDF2F2] px-[16px] py-[14px] text-[15px] text-[#9B1C1C]">
                            ⚠️ {{ $message }}
                        </div>
                    @enderror
                </div>
            </div>
        </div>

        <div
            x-ref="footerBar"
            class="shrink-0 overflow-hidden bg-transparent"
            :class="buttonsHidden ? 'max-h-0' : 'max-h-[82px]'"
            style="transition: max-height 300ms ease;"
        >
            <div class="border-t border-[#E3EAF0] bg-white/95 px-5 pb-5 pt-4 backdrop-blur transition-all duration-300 supports-[backdrop-filter]:bg-white/80">
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
                            wire:target="submit"
                            :disabled="blank($type) || blank(trim($comment))"
                        >
                            <span wire:loading.remove wire:target="submit">
                                Отправить
                            </span>

                            <span
                                wire:loading
                                wire:target="submit"
                                class="inline-flex items-center"
                            >
                                <span>Отправляем</span>

                                <span class="inline-flex items-center relative top-[-1px] leading-none">
                                    <span class="inline-block animate-bounce [animation-delay:0ms]">.</span>
                                    <span class="inline-block animate-bounce [animation-delay:150ms]">.</span>
                                    <span class="inline-block animate-bounce [animation-delay:300ms]">.</span>
                                </span>
                            </span>
                        </x-ui.button>
                    </div>
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
                    Ваш вопрос отправлен!
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