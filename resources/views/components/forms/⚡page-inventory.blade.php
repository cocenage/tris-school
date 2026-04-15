<?php

use App\Models\InventoryItem;
use App\Models\InventoryRequest;
use App\Models\InventoryRequestItem;
use App\Services\Forms\InventoryRequestTelegramService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component {
    public string $search = '';
    public array $selectedItems = [];
    public string $comment = '';
    public bool $successSheetOpen = false;
    public ?string $successMessage = null;

    public function getAvailableItemsProperty(): Collection
    {
        return InventoryItem::query()
            ->where('is_active', true)
            ->when(
                filled($this->search),
                fn ($query) => $query->where('name', 'like', '%' . trim($this->search) . '%')
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

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

    public function addItem(int $itemId): void
    {
        $item = InventoryItem::query()
            ->where('is_active', true)
            ->find($itemId);

        if (! $item) {
            return;
        }

        $existingIndex = collect($this->selectedItems)->search(
            fn ($selected) => (int) ($selected['inventory_item_id'] ?? 0) === $item->id
        );

        if ($existingIndex !== false) {
            $this->selectedItems[$existingIndex]['requested_qty'] = (int) $this->selectedItems[$existingIndex]['requested_qty'] + 1;
            return;
        }

        $this->selectedItems[] = [
            'inventory_item_id' => $item->id,
            'item_name' => $item->name,
            'requested_qty' => 1,
        ];
    }

    public function removeItem(int $index): void
    {
        unset($this->selectedItems[$index]);
        $this->selectedItems = array_values($this->selectedItems);
    }

    public function resetForm(): void
    {
        $this->selectedItems = [];
        $this->comment = '';
        $this->search = '';

        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function submit(InventoryRequestTelegramService $telegram): void
    {
        $items = collect($this->selectedItems)
            ->map(function ($item) {
                return [
                    'inventory_item_id' => (int) ($item['inventory_item_id'] ?? 0),
                    'item_name' => trim((string) ($item['item_name'] ?? '')),
                    'requested_qty' => max(1, (int) ($item['requested_qty'] ?? 1)),
                ];
            })
            ->filter(fn ($item) => $item['inventory_item_id'] > 0 && filled($item['item_name']))
            ->values();

        if ($items->isEmpty()) {
            $this->toast(
                'warning',
                'Нет товаров',
                'Выбери хотя бы одну позицию'
            );
            return;
        }

        if (filled(trim($this->comment)) && mb_strlen(trim($this->comment)) > 500) {
            $this->addError('comment', 'Максимум 500 символов.');

            $this->toast(
                'warning',
                'Слишком длинный комментарий',
                'Максимум 500 символов'
            );
            return;
        }

        try {
            $request = DB::transaction(function () use ($items) {
                $request = InventoryRequest::create([
                    'user_id' => Auth::id(),
                    'status' => 'pending',
                    'comment' => filled(trim($this->comment)) ? trim($this->comment) : null,
                    'requested_at' => now(),
                ]);

                foreach ($items as $item) {
                    InventoryRequestItem::create([
                        'inventory_request_id' => $request->id,
                        'inventory_item_id' => $item['inventory_item_id'],
                        'item_name' => $item['item_name'],
                        'requested_qty' => $item['requested_qty'],
                        'approved_qty' => 0,
                        'status' => 'pending',
                    ]);
                }

                return $request;
            });

            try {
                $telegram->sendCreated($request);
            } catch (\Throwable $e) {
                Log::error('Inventory telegram failed but request saved', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->resetForm();

            $this->successMessage = 'Ответ ожидайте сегодня с 10:00 до 18:00';
            $this->successSheetOpen = true;
        } catch (\Throwable $e) {
            Log::error('Inventory request submit error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

            $this->addError('form', 'Произошла ошибка при отправке. Пожалуйста, попробуйте позже.');

            $this->toast(
                'error',
                'Не получилось отправить',
                'Попробуй ещё раз через пару минут',
                5000
            );
        }
    }
};
?>

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <form wire:submit="submit" class="flex h-full min-h-0 flex-col">
        <div class="flex-1 min-h-0 overflow-y-auto">
            <div class="min-h-full rounded-t-[38px] bg-white">
                <div class="px-[16px] pt-[18px] pb-[28px]">
                    <div class="mb-[24px]">
                        <h1 class="text-[24px] font-semibold text-[#213259]">
                            Запрос инвентаря
                        </h1>

                        <p class="mt-[8px] text-[14px] leading-[1.5] text-[#6F8096]">
                            Выберите нужные товары и отправьте заявку
                        </p>
                    </div>

                    <div class="mb-[18px]">
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="search"
                            placeholder="Поиск товара"
                            class="w-full rounded-[23px] border border-[#D9E4EC] bg-[#EAF1F6] px-[20px] py-[15px] text-[16px] text-[#213259] placeholder:text-[#6F8096] outline-none"
                        >
                    </div>

                    <div class="space-y-[10px]">
                        @forelse ($this->availableItems as $item)
                            <div class="flex items-center justify-between gap-[12px] rounded-[24px] border border-[#D9E4EC] bg-[#EAF1F6] px-[16px] py-[14px]">
                                <div class="min-w-0">
                                    <div class="text-[15px] font-medium text-[#213259]">
                                        {{ $item->name }}
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    wire:click="addItem({{ $item->id }})"
                                    class="inline-flex h-[38px] shrink-0 items-center justify-center rounded-full bg-[#213259] px-[16px] text-[13px] font-semibold text-white transition active:scale-[0.97]"
                                >
                                    Добавить
                                </button>
                            </div>
                        @empty
                            <div class="rounded-[24px] border border-[#D9E4EC] bg-[#EAF1F6] px-[16px] py-[18px] text-[14px] text-[#6F8096]">
                                Ничего не найдено
                            </div>
                        @endforelse
                    </div>

                    <div class="mt-[24px]">
                        <h2 class="mb-[14px] text-[16px] font-semibold text-[#213259]">
                            Выбрано
                        </h2>

                        <div class="space-y-[10px]">
                            @forelse ($selectedItems as $index => $item)
                                <div class="rounded-[24px] border border-[#D9E4EC] bg-white px-[16px] py-[14px]">
                                    <div class="mb-[10px] flex items-center justify-between gap-[12px]">
                                        <div class="text-[15px] font-medium text-[#213259]">
                                            {{ $item['item_name'] }}
                                        </div>

                                        <button
                                            type="button"
                                            wire:click="removeItem({{ $index }})"
                                            class="text-[13px] font-medium text-[#C53B32]"
                                        >
                                            Удалить
                                        </button>
                                    </div>

                                    <input
                                        type="number"
                                        min="1"
                                        wire:model.live="selectedItems.{{ $index }}.requested_qty"
                                        class="w-full rounded-[20px] border border-[#D9E4EC] bg-[#F8FBFD] px-[16px] py-[14px] text-[15px] text-[#213259] outline-none"
                                    >
                                </div>
                            @empty
                                <div class="rounded-[24px] border border-[#D9E4EC] bg-[#EAF1F6] px-[16px] py-[18px] text-[14px] text-[#6F8096]">
                                    Пока ничего не выбрано
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <div class="mt-[24px]">
                        <h2 class="mb-[14px] text-[16px] font-semibold text-[#213259]">
                            Комментарий
                        </h2>

                        <textarea
                            wire:model.live.debounce.500ms="comment"
                            rows="4"
                            maxlength="500"
                            placeholder="Напишите комментарий, если нужно"
                            class="w-full rounded-[23px] border border-[#D9E4EC] bg-[#EAF1F6] px-[20px] py-[15px] text-[16px] text-[#213259] placeholder:text-[16px] placeholder:text-[#6F8096] outline-none"
                        ></textarea>

                        @error('comment')
                            <div class="mt-[8px] px-[4px] text-[15px] text-[#D92D20]">
                                {{ $message }}
                            </div>
                        @enderror
                    </div>

                    @error('form')
                        <div class="mt-[14px] rounded-[23px] bg-[#FDF2F2] px-[16px] py-[14px] text-[15px] text-[#9B1C1C]">
                            ⚠️ {{ $message }}
                        </div>
                    @enderror
                </div>
            </div>
        </div>

        <div class="shrink-0 border-t border-[#E3EAF0] bg-white px-[16px] pt-[14px] pb-[18px] shadow-[0_-8px_24px_rgba(33,50,89,0.04)]">
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
                    >
                        <span wire:loading.remove wire:target="submit">
                            Отправить заявку
                        </span>

                        <span wire:loading wire:target="submit">
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

                <h1 class="mt-[28px] text-[22px] font-semibold tracking-[-0.02em] text-[#111111]">
                    Заявка на инвентарь отправлена!
                </h1>

                <p class="pt-[18px] text-[15px] leading-[1.5] text-black/55">
                    {{ $successMessage }}
                </p>

                <div class="flex gap-[10px] pt-[32px]">
                    <x-ui.button
                        variant="secondary"
                        @click="sheetOpen = false"
                    >
                        Закрыть
                    </x-ui.button>

                    <x-ui.button
                        variant="primary"
                        href="{{ route('page-profile.applications') }}"
                    >
                        Мои заявки
                    </x-ui.button>
                </div>
            </div>
        </x-ui.bottom-sheet>
    </div>
</div>