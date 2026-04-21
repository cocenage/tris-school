<?php

use App\Models\FeedbackSuggestion;
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
                    'path' => $file->store('staff-forms/feedback', 'public'),
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];
            }

            $record = FeedbackSuggestion::create([
                'user_id' => Auth::id(),
                'type' => trim($this->type),
                'comment' => trim($this->comment),
                'attachments' => $storedAttachments,
                'status' => 'pending',
            ]);

            try {
                $telegram->sendFeedbackSuggestion($record);
            } catch (\Throwable $e) {
                Log::error('Feedback suggestion telegram failed but record saved', [
                    'record_id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->resetForm();

            $this->successMessage = 'Ваш отзыв или предложение отправлены.';
            $this->successSheetOpen = true;
        } catch (\Throwable $e) {
            Log::error('Feedback suggestion submit error', [
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
<div
    x-data="{
        lastScroll: 0,
        showFloatingBar: true,
        docked: true,

        init() {
            const scrollArea = this.$refs.scrollArea;
            const footerAnchor = this.$refs.footerAnchor;

            if (!scrollArea || !footerAnchor) return;

            this.lastScroll = scrollArea.scrollTop;

            const updateDockedState = () => {
                const scrollRect = scrollArea.getBoundingClientRect();
                const anchorRect = footerAnchor.getBoundingClientRect();

                this.docked = anchorRect.top <= scrollRect.bottom;
            };

            updateDockedState();

            scrollArea.addEventListener('scroll', () => {
                const current = scrollArea.scrollTop;

                updateDockedState();

                if (current <= 8) {
                    this.showFloatingBar = true;
                    this.lastScroll = current;
                    return;
                }

                if (current > this.lastScroll + 8) {
                    this.showFloatingBar = false;
                } else if (current < this.lastScroll - 8) {
                    this.showFloatingBar = true;
                }

                this.lastScroll = current;
            }, { passive: true });

            window.addEventListener('resize', updateDockedState);
        }
    }"
    class="flex h-full min-h-0 flex-col bg-[#F5F6F7]"
>
    <form wire:submit="submit" class="flex h-full min-h-0 flex-col">
        <div class="flex-1 min-h-0 overflow-y-auto">
            <div class="mx-auto w-full max-w-[768px]">
                <div class="rounded-t-[32px] bg-white px-[20px] pb-[140px] pt-[20px]">
       

                    <div class="space-y-[18px]">
                        <div>
                            <label class="mb-[8px] block text-[14px] font-medium text-[#111111]">
                                Тип вопроса
                            </label>

                            <div class="relative">
                                <select
                                    wire:model="type"
                                    class="w-full appearance-none rounded-[18px] border border-[#E7E7E7] bg-[#F8F8F8] px-[16px] py-[14px] pr-[42px] text-[15px] text-[#111111] outline-none transition focus:border-[#D6D6D6] focus:bg-white focus:ring-0"
                                >
                                    <option value="">Выберите тип</option>
                                    <option value="Отзыв">Отзыв</option>
                                    <option value="Предложение">Предложение</option>
                                    <option value="Жалоба">Жалоба</option>
                                    <option value="Идея">Идея</option>
                                    <option value="Другое">Другое</option>
                                </select>

                                <div class="pointer-events-none absolute inset-y-0 right-[16px] flex items-center text-black/35">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                                    </svg>
                                </div>
                            </div>

                            @error('type')
                                <div class="mt-[8px] text-[13px] text-[#D92D20]">
                                    {{ $message }}
                                </div>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-[8px] block text-[14px] font-medium text-[#111111]">
                                Комментарий
                            </label>

                            <textarea
                                wire:model.live.debounce.400ms="comment"
                                rows="6"
                                maxlength="2000"
                                placeholder="Например: было бы удобно, если бы..."
                                class="w-full rounded-[18px] border border-[#E7E7E7] bg-[#F8F8F8] px-[16px] py-[14px] text-[15px] text-[#111111] placeholder:text-black/35 outline-none transition focus:border-[#D6D6D6] focus:bg-white focus:ring-0"
                            ></textarea>

                            <div class="mt-[8px] flex items-center justify-between gap-[12px]">
                                @error('comment')
                                    <div class="text-[13px] text-[#D92D20]">
                                        {{ $message }}
                                    </div>
                                @else
                           
                                @enderror

                                
                            </div>
                        </div>

                        <div>
                            <label class="mb-[8px] block text-[14px] font-medium text-[#111111]">
                                Вложения
                                <span class="font-normal text-black/35">(необязательно)</span>
                            </label>

                            <label class="flex cursor-pointer items-center justify-center rounded-[18px] border border-dashed border-[#DADADA] bg-[#FAFAFA] px-[16px] py-[18px] text-center transition hover:bg-white">
                                <input
                                    type="file"
                                    wire:model="attachments"
                                    multiple
                                    class="hidden"
                                >

                                <div>
                                    <div class="text-[14px] font-medium text-[#111111]">
                                        Добавить файлы
                                    </div>
                                    <div class="mt-[4px] text-[12px] text-black/40">
                                        Фото, скриншоты, документы
                                    </div>
                                </div>
                            </label>

                            @error('attachments.*')
                                <div class="mt-[8px] text-[13px] text-[#D92D20]">
                                    {{ $message }}
                                </div>
                            @enderror

                            @if (! empty($attachments))
                                <div class="mt-[10px] space-y-[8px]">
                                    @foreach ($attachments as $index => $file)
                                        <div class="flex items-center justify-between gap-[10px] rounded-[16px] border border-[#ECECEC] bg-[#F8F8F8] px-[14px] py-[12px]">
                                            <div class="min-w-0 truncate text-[14px] text-[#111111]">
                                                {{ $file->getClientOriginalName() }}
                                            </div>

                                            <button
                                                type="button"
                                                wire:click="removeAttachment({{ $index }})"
                                                class="shrink-0 text-[12px] font-medium text-black/45 transition hover:text-black"
                                            >
                                                Убрать
                                            </button>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        @error('form')
                            <div class="rounded-[18px] border border-[#F3D1D1] bg-[#FFF6F6] px-[14px] py-[12px] text-[14px] text-[#9B1C1C]">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="sticky bottom-0 z-20 shrink-0 border-t border-[#EAEAEA] bg-white/95 backdrop-blur">
            <div class="mx-auto w-full max-w-[768px] p-[16px]">
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