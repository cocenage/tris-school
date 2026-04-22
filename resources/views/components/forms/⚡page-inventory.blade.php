<?php

use App\Models\InventoryItem;
use App\Models\InventoryRequest;
use App\Models\InventoryRequestLine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component
{
    public array $catalog = [];
    public array $selected = [];
    public string $search = '';

    public bool $cartSheetOpen = false;
    public bool $successSheetOpen = false;
    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->catalog = $this->buildCatalog();
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

    protected function itemMain(InventoryItem $item): array
    {
        $main = $item->main;

        if (is_string($main)) {
            $decoded = json_decode($main, true);
            $main = is_array($decoded) ? $decoded : [];
        }

        return is_array($main) ? $main : [];
    }

    protected function buildCatalog(): array
    {
        return InventoryItem::query()
            ->where('active', true)
            ->latest('id')
            ->get()
            ->map(function (InventoryItem $item) {
                $main = $this->itemMain($item);

                $productName = trim((string) ($main['name'] ?? $main['title'] ?? $main['product_name'] ?? 'Без названия'));

                $variants = collect($main['variants'] ?? [])
                    ->filter(fn ($variant) => is_array($variant))
                    ->map(function (array $variant, int $index) use ($item, $productName) {
                        $type = filled($variant['type'] ?? null)
                            ? trim((string) $variant['type'])
                            : (filled($variant['type_name'] ?? null) ? trim((string) $variant['type_name']) : null);

                        $size = filled($variant['size'] ?? null)
                            ? trim((string) $variant['size'])
                            : (filled($variant['size_name'] ?? null) ? trim((string) $variant['size_name']) : null);

                        $parts = array_values(array_filter([$type, $size], fn ($value) => filled($value)));

                        return [
                            'key' => (string) $item->id . '_' . $index,
                            'inventory_item_id' => $item->id,
                            'item_name' => $productName,
                            'type_name' => $type,
                            'size_name' => $size,
                            'variant_label' => ! empty($parts) ? implode(' • ', $parts) : null,
                        ];
                    })
                    ->values()
                    ->all();

                if (empty($variants)) {
                    $variants[] = [
                        'key' => (string) $item->id . '_base',
                        'inventory_item_id' => $item->id,
                        'item_name' => $productName,
                        'type_name' => null,
                        'size_name' => null,
                        'variant_label' => null,
                    ];
                }

                return [
                    'id' => $item->id,
                    'name' => $productName,
                    'variants' => $variants,
                ];
            })
            ->values()
            ->all();
    }

    public function selectOne(string $key): void
    {
        $this->selected[$key]['requested_qty'] = max(1, (int) ($this->selected[$key]['requested_qty'] ?? 0));
    }

    public function increment(string $key): void
    {
        $current = (int) ($this->selected[$key]['requested_qty'] ?? 0);
        $this->selected[$key]['requested_qty'] = $current + 1;
    }

    public function decrement(string $key): void
    {
        $current = (int) ($this->selected[$key]['requested_qty'] ?? 0);
        $next = max($current - 1, 0);

        if ($next <= 0) {
            unset($this->selected[$key]);
            return;
        }

        $this->selected[$key]['requested_qty'] = $next;
    }

    public function removeSelected(string $key): void
    {
        unset($this->selected[$key]);
    }

    public function updatedSelected($value, string $name): void
    {
        if (! str_ends_with($name, '.requested_qty')) {
            return;
        }

        $parts = explode('.', $name);
        $key = $parts[0] ?? null;

        if (! $key) {
            return;
        }

        $qty = max(0, (int) $value);

        if ($qty <= 0) {
            unset($this->selected[$key]);
            return;
        }

        $this->selected[$key]['requested_qty'] = $qty;
    }

    protected function selectedLines(): array
    {
        $lines = [];

        foreach ($this->catalog as $product) {
            foreach (($product['variants'] ?? []) as $variant) {
                $key = $variant['key'];
                $requestedQty = (int) ($this->selected[$key]['requested_qty'] ?? 0);

                if ($requestedQty <= 0) {
                    continue;
                }

                $lines[] = [
                    'key' => $key,
                    'inventory_item_id' => (int) $variant['inventory_item_id'],
                    'item_name' => (string) $variant['item_name'],
                    'type_name' => $variant['type_name'],
                    'size_name' => $variant['size_name'],
                    'variant_label' => $variant['variant_label'],
                    'requested_qty' => $requestedQty,
                ];
            }
        }

        return $lines;
    }

    public function getFilteredCatalogProperty(): array
    {
        $search = mb_strtolower(trim($this->search));

        if ($search === '') {
            return $this->catalog;
        }

        return collect($this->catalog)
            ->map(function (array $product) use ($search) {
                $productName = mb_strtolower($product['name'] ?? '');

                $variants = collect($product['variants'] ?? [])
                    ->filter(function (array $variant) use ($search, $productName) {
                        $variantText = mb_strtolower(trim((string) ($variant['variant_label'] ?? '')));
                        return str_contains($productName, $search) || str_contains($variantText, $search);
                    })
                    ->values()
                    ->all();

                if (empty($variants) && ! str_contains($productName, $search)) {
                    return null;
                }

                return [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'variants' => ! empty($variants) ? $variants : ($product['variants'] ?? []),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function getSelectedSummaryProperty(): array
    {
        return $this->selectedLines();
    }

    public function getTotalSelectedQtyProperty(): int
    {
        return collect($this->selectedSummary)->sum('requested_qty');
    }

    public function getTotalSelectedLinesProperty(): int
    {
        return count($this->selectedSummary);
    }

    public function resetForm(): void
    {
        $this->selected = [];
        $this->search = '';

        $this->resetErrorBag();
        $this->resetValidation();
    }

    public function openCart(): void
    {
        if (empty($this->selectedSummary)) {
            return;
        }

        $this->cartSheetOpen = true;
    }

    public function closeCart(): void
    {
        $this->cartSheetOpen = false;
    }

    public function closeSuccessSheet(): void
    {
        $this->successSheetOpen = false;
        $this->successMessage = null;
    }

    public function submit(): void
    {
        $lines = $this->selectedLines();

        if (empty($lines)) {
            $this->toast(
                'warning',
                'Ничего не выбрано',
                'Добавьте хотя бы один товар в корзину'
            );
            return;
        }

        try {
            DB::transaction(function () use ($lines) {
                $request = InventoryRequest::create([
                    'user_id' => Auth::id(),
                    'status' => 'pending',
                    'submitted_at' => now(),
                ]);

                foreach ($lines as $line) {
                    InventoryRequestLine::create([
                        'inventory_request_id' => $request->id,
                        'inventory_item_id' => $line['inventory_item_id'],
                        'user_id' => Auth::id(),
                        'item_name' => $line['item_name'],
                        'type_name' => $line['type_name'],
                        'size_name' => $line['size_name'],
                        'variant_label' => $line['variant_label'],
                        'requested_qty' => (int) $line['requested_qty'],
                        'issued_qty' => 0,
                        'status' => 'pending',
                    ]);
                }
            });

            $this->selected = [];
            $this->search = '';
            $this->cartSheetOpen = false;

            $this->resetErrorBag();
            $this->resetValidation();

            $this->successMessage = $this->buildSuccessMessage();
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

@push('meta')
<title>Инвентарь • Tris Service Academy</title>
<meta name="description" content="">
<meta name="keywords" content="">
@endpush

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
            Заявка на инвентарь
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
    <div class="flex h-full min-h-0 flex-col">
        <div
            x-ref="scrollArea"
            class="flex-1 min-h-0 overflow-y-auto"
        >
            <div class="min-h-full rounded-t-[38px] bg-white">
                <div class="p-[20px] pb-[110px]">
                    <div class="mb-[24px]">
                        <h2 class="mb-[14px] text-[16px] font-medium text-[#213259]">
                            Что нужно?
                        </h2>

                        <label class="block">
                            <span class="relative block">
                                <span class="pointer-events-none absolute left-[18px] top-1/2 -translate-y-1/2 text-black/35">
                                    <x-heroicon-o-magnifying-glass class="h-[18px] w-[18px]" />
                                </span>

                                <input
                                    type="text"
                                    wire:model.live.debounce.250ms="search"
                                    placeholder="Поиск товара или варианта"
                                    class="h-[50px] w-full rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] pl-[46px] pr-[16px] text-[15px] text-[#213259] outline-none transition focus:border-[#D6D6D6] focus:bg-white focus:ring-0"
                                >
                            </span>
                        </label>
                    </div>

                    <div class="space-y-[14px]">
                        @forelse ($this->filteredCatalog as $product)
                            <section class="rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] p-[16px]">
                                <div class="mb-[14px]">
                                    <h2 class="text-[16px] font-medium text-[#213259]">
                                        {{ $product['name'] }}
                                    </h2>
                                </div>

                                <div class="space-y-[10px]">
                                    @foreach (($product['variants'] ?? []) as $variant)
                                        @php
                                            $key = $variant['key'];
                                            $requestedQty = (int) ($selected[$key]['requested_qty'] ?? 0);
                                            $isSelected = $requestedQty > 0;
                                        @endphp

                                        <div class="rounded-[20px] border border-[#E7E7E7] bg-white px-[14px] py-[14px]">
                                            <div class="mb-[12px] flex items-start justify-between gap-[10px]">
                                                <div class="min-w-0">
                                                    <div class="text-[15px] font-medium text-[#213259]">
                                                        {{ $variant['item_name'] }}
                                                    </div>

                                                    <div class="mt-[2px] text-[13px] text-black/40">
                                                        {{ $variant['variant_label'] ?: 'Стандартный вариант' }}
                                                    </div>
                                                </div>

                                                @if ($isSelected)
                                                    <div class="shrink-0 rounded-full bg-[#213259] px-[9px] py-[4px] text-[12px] font-medium text-white">
                                                        {{ $requestedQty }}
                                                    </div>
                                                @endif
                                            </div>

                                            @if (! $isSelected)
                                                <button
                                                    type="button"
                                                    wire:click="selectOne('{{ $key }}')"
                                                    class="flex h-[42px] w-full items-center justify-center rounded-full bg-[#213259] px-4 text-[14px] font-medium text-white transition duration-200 hover:opacity-95 active:scale-[0.99]"
                                                >
                                                    Добавить
                                                </button>
                                            @else
                                                <div class="flex items-center gap-[8px]">
                                                    <button
                                                        type="button"
                                                        wire:click="decrement('{{ $key }}')"
                                                        class="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-full border border-[#D7E2EA] bg-white text-[18px] text-[#213259] transition duration-200 hover:bg-[#F8FBFD] active:scale-[0.97]"
                                                    >
                                                        −
                                                    </button>

                                                    <input
                                                        type="number"
                                                        min="0"
                                                        wire:model.live="selected.{{ $key }}.requested_qty"
                                                        class="h-[42px] min-w-0 flex-1 rounded-full border border-[#D7E2EA] bg-white px-4 text-center text-[15px] font-medium text-[#213259] outline-none focus:border-[#9FB4C9] focus:ring-0"
                                                    >

                                                    <button
                                                        type="button"
                                                        wire:click="increment('{{ $key }}')"
                                                        class="flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-full border border-[#D7E2EA] bg-white text-[18px] text-[#213259] transition duration-200 hover:bg-[#F8FBFD] active:scale-[0.97]"
                                                    >
                                                        +
                                                    </button>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @empty
                            <div class="rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] px-[18px] py-[18px] text-center text-[15px] text-black/45">
                                Ничего не найдено.
                            </div>
                        @endforelse
                    </div>

                    @error('form')
                        <div class="mt-[14px] rounded-[23px] bg-[#FDF2F2] px-[16px] py-[14px] text-[15px] text-[#9B1C1C]">
                            ⚠️ {{ $message }}
                        </div>
                    @enderror
                </div>
            </div>
        </div>

        @if (! empty($this->selectedSummary))
            <div
                x-ref="footerBar"
                class="shrink-0 overflow-hidden bg-transparent"
                :class="buttonsHidden ? 'max-h-0' : 'max-h-[90px]'"
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
                            <button
                                type="button"
                                wire:click="openCart"
                                class="flex h-[48px] w-full items-center justify-between rounded-full bg-[#213259] px-[18px] text-left text-white transition duration-200 hover:opacity-95 active:scale-[0.99]"
                            >
                                <div class="min-w-0">
                                    <div class="truncate text-[14px] font-medium">
                                        Корзина
                                    </div>

                                    <div class="mt-[1px] truncate text-[12px] text-white/75">
                                        {{ $this->totalSelectedLines }} поз. • {{ $this->totalSelectedQty }} шт.
                                    </div>
                                </div>

                                <div class="shrink-0 text-[13px] font-medium">
                                    Открыть
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div x-data="{ sheetOpen: @entangle('cartSheetOpen').live }">
        <x-ui.bottom-sheet x-model="sheetOpen">
            <div class="p-5">
                <div class="text-center">
                    <h2 class="mt-2 text-[22px] font-semibold tracking-[-0.02em] text-[#111111]">
                        Корзина
                    </h2>

                    <p class="pt-[10px] text-[15px] leading-[1.5] text-black/55">
                        Проверьте выбранные позиции перед отправкой.
                    </p>
                </div>

                <div class="mt-6 space-y-3">
                    @forelse ($this->selectedSummary as $line)
                        <div class="rounded-[22px] border border-[#E7E7E7] bg-[#F8F8F8] p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-[15px] font-medium text-[#213259]">
                                        {{ $line['item_name'] }}
                                    </div>

                                    <div class="mt-1 text-[13px] text-black/40">
                                        {{ $line['variant_label'] ?: 'Стандартный вариант' }}
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    wire:click="removeSelected('{{ $line['key'] }}')"
                                    class="shrink-0 rounded-full bg-white px-3 py-1.5 text-[12px] text-[#213259] transition duration-200 hover:bg-[#EEF4F8]"
                                >
                                    Убрать
                                </button>
                            </div>

                            <div class="mt-4 flex items-center gap-2">
                                <button
                                    type="button"
                                    wire:click="decrement('{{ $line['key'] }}')"
                                    class="flex h-[40px] w-[40px] items-center justify-center rounded-full border border-[#D7E2EA] bg-white text-[18px] text-[#213259]"
                                >
                                    −
                                </button>

                                <input
                                    type="number"
                                    min="0"
                                    wire:model.live="selected.{{ $line['key'] }}.requested_qty"
                                    class="h-[40px] min-w-0 flex-1 rounded-full border border-[#D7E2EA] bg-white px-4 text-center text-[15px] font-medium text-[#213259] outline-none focus:border-[#9FB4C9] focus:ring-0"
                                >

                                <button
                                    type="button"
                                    wire:click="increment('{{ $line['key'] }}')"
                                    class="flex h-[40px] w-[40px] items-center justify-center rounded-full border border-[#D7E2EA] bg-white text-[18px] text-[#213259]"
                                >
                                    +
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-[22px] bg-[#F8F8F8] p-4 text-center text-[14px] text-black/45">
                            Корзина пуста.
                        </div>
                    @endforelse
                </div>

                <div class="mt-6 rounded-[22px] bg-[#EEF4F8] p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-[14px] font-semibold text-[#213259]">
                                Итого
                            </div>

                            <div class="mt-1 text-[13px] text-black/45">
                                {{ $this->totalSelectedLines }} позиций • {{ $this->totalSelectedQty }} шт.
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-[10px] pt-[20px]">
                    <div class="col-span-1">
                        <x-ui.button
                            type="button"
                            variant="secondary"
                            wire:click="closeCart"
                        >
                            Назад
                        </x-ui.button>
                    </div>

                    <div class="col-span-2">
                        <x-ui.button
                            type="button"
                            variant="primary"
                            wire:click="submit"
                            wire:loading.attr="disabled"
                            wire:target="submit"
                        >
                            <span wire:loading.remove wire:target="submit">
                                Отправить заявку
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
        </x-ui.bottom-sheet>
    </div>

    <div x-data="{ sheetOpen: @entangle('successSheetOpen').live }">
        <x-ui.bottom-sheet x-model="sheetOpen">
            <div class="p-5 text-center">
                <img
                    class="mt-[28px] h-[135px] w-full object-contain"
                    src="{{ asset('images/success.webp') }}"
                    alt="success"
                >

                <h1 class="mt-[28px] text-[22px] font-semibold tracking-[-0.02em] text-[#111111]">
                    Заявка успешно отправлена!
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