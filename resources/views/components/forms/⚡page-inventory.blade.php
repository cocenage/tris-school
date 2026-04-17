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

            $this->successMessage = 'Ваша заявка отправлена. Её статус можно посмотреть в разделе заявок.';
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

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="flex-1 min-h-0 overflow-y-auto">
        <div class="mx-auto w-full max-w-[720px] px-4 pb-[120px] pt-4 md:px-6 md:pt-6">
            <!-- <div class="mb-4 rounded-[32px] bg-white p-5 shadow-[0_10px_30px_rgba(31,41,55,0.04)] md:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h1 class="text-[24px] font-semibold tracking-[-0.02em] text-[#111111]">
                            Инвентарь
                        </h1>

                        <p class="mt-2 text-[15px] leading-[1.5] text-black/55">
                            Выберите, что вам нужно, и отправьте заявку.
                        </p>
                    </div>

                    <div class="hidden rounded-full bg-[#EEF4F8] px-3 py-2 text-[12px] text-[#213259] md:block">
                        Мини-магазин
                    </div>
                </div>

                <div class="mt-5">
                    <label class="relative block">
                        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-[#7B8794]">
                            🔎
                        </span>

                        <input
                            type="text"
                            wire:model.live.debounce.250ms="search"
                            placeholder="Поиск товара или варианта"
                            class="h-[52px] w-full rounded-full border border-[#D9E4EC] bg-[#F8FBFD] pl-11 pr-4 text-[15px] text-[#213259] outline-none transition duration-200 focus:border-[#9FB4C9] focus:bg-white focus:ring-0"
                        >
                    </label>
                </div>
            </div> -->

            <div class="space-y-4">
                @forelse ($this->filteredCatalog as $product)
                    <section class="rounded-[32px] bg-white p-4 md:p-5">
                        <div class="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-[18px] font-semibold tracking-[-0.02em] text-[#111111]">
                                    {{ $product['name'] }}
                                </h2>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @foreach (($product['variants'] ?? []) as $variant)
                                @php
                                    $key = $variant['key'];
                                    $requestedQty = (int) ($selected[$key]['requested_qty'] ?? 0);
                                    $isSelected = $requestedQty > 0;
                                @endphp

                                <div class="overflow-hidden rounded-[26px] border transition duration-200 {{ $isSelected ? 'border-[#B8D1E6] bg-[#F7FBFF] shadow-[0_10px_24px_rgba(49,129,187,0.12)]' : 'border-[#E6EDF3] bg-[#FCFDFE]' }}">
                                    <div class="p-4">
                                        <div class="mb-4 flex items-start justify-between gap-3">
                                            <div class="flex min-w-0 items-start gap-3">
                                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-[18px] bg-[#EEF4F8] text-[20px]">
                                                    📦
                                                </div>

                                                <div class="min-w-0">
                                                    <div class="truncate text-[15px] font-semibold text-[#213259]">
                                                        {{ $variant['item_name'] }}
                                                    </div>

                                                    <div class="mt-1 text-[13px] text-[#6F8096]">
                                                        {{ $variant['variant_label'] ?: 'Стандартный вариант' }}
                                                    </div>
                                                </div>
                                            </div>

                                            @if ($isSelected)
                                                <div class="shrink-0 rounded-full bg-[#213259] px-2.5 py-1 text-[12px] text-white">
                                                    {{ $requestedQty }}
                                                </div>
                                            @endif
                                        </div>

                                        @if (! $isSelected)
                                            <button
                                                type="button"
                                                wire:click="selectOne('{{ $key }}')"
                                                class="flex h-[42px] w-full items-center justify-center rounded-full bg-[#213259] px-4 text-[14px] font-medium text-white transition duration-200 hover:opacity-90 active:scale-[0.99]"
                                            >
                                                Добавить
                                            </button>
                                        @else
                                            <div class="flex items-center gap-2">
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
                                </div>
                            @endforeach
                        </div>
                    </section>
                @empty
                    <div class="rounded-[32px] bg-white p-6 text-center text-[15px] text-[#6F8096]">
                        Ничего не найдено.
                    </div>
                @endforelse
            </div>

            @error('form')
                <div class="mt-4 rounded-[24px] bg-[#FDF2F2] px-4 py-4 text-[15px] text-[#9B1C1C]">
                    ⚠️ {{ $message }}
                </div>
            @enderror
        </div>
    </div>

    @if (! empty($this->selectedSummary))
        <div class="pointer-events-none fixed inset-x-0 bottom-0 z-30 px-4 pb-4 md:px-6">
            <div class="mx-auto w-full max-w-[720px] pointer-events-auto">
                <div class="rounded-[26px] border border-[#D9E4EC] bg-white/95 p-3 backdrop-blur">
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            wire:click="openCart"
                            class="flex min-w-0 flex-1 items-center justify-between rounded-[20px] bg-[#213259] px-4 py-3 text-left text-white transition duration-200 hover:opacity-95 active:scale-[0.99]"
                        >
                            <div class="min-w-0">
                                <div class="truncate text-[14px] font-semibold">
                                    Корзина
                                </div>

                                <div class="mt-1 truncate text-[12px] text-white/75">
                                    {{ $this->totalSelectedLines }} поз. • {{ $this->totalSelectedQty }} шт.
                                </div>
                            </div>

                            <div class="shrink-0 text-[13px] font-medium">
                                Открыть
                            </div>
                        </button>

                        <button
                            type="button"
                            wire:click="resetForm"
                            class="flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-[18px] border border-[#D9E4EC] bg-[#F8FBFD] text-[#213259] transition duration-200 hover:bg-white active:scale-[0.97]"
                        >
                            ✕
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

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
                        <div class="rounded-[22px] border border-[#E6EDF3] bg-[#F8FBFD] p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-[15px] font-semibold text-[#213259]">
                                        {{ $line['item_name'] }}
                                    </div>

                                    <div class="mt-1 text-[13px] text-[#6F8096]">
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
                        <div class="rounded-[22px] bg-[#F8FBFD] p-4 text-center text-[14px] text-[#6F8096]">
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

                            <div class="mt-1 text-[13px] text-[#6F8096]">
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

                            <span wire:loading wire:target="submit">
                                Отправляем...
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