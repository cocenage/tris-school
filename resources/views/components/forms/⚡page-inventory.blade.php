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

    public bool $successSheetOpen = false;
    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->catalog = $this->buildCatalog();
        $this->restoreDraft();
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

    protected function draftKey(): string
    {
        return 'inventory_request_draft_' . (Auth::id() ?: 'guest');
    }

    protected function persistDraft(): void
    {
        session()->put($this->draftKey(), [
            'selected' => $this->selected,
            'search' => $this->search,
        ]);
    }

    protected function restoreDraft(): void
    {
        $draft = session()->get($this->draftKey());

        if (! is_array($draft)) {
            return;
        }

        $this->selected = is_array($draft['selected'] ?? null) ? $draft['selected'] : [];
        $this->search = (string) ($draft['search'] ?? '');
    }

    protected function clearDraft(): void
    {
        session()->forget($this->draftKey());
    }

    public function updatedSearch(): void
    {
        $this->persistDraft();
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

                $productName = trim((string) (
                    $main['name']
                    ?? $main['title']
                    ?? $main['product_name']
                    ?? 'Без названия'
                ));

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
                            'key' => $item->id . '_' . $index,
                            'inventory_item_id' => $item->id,
                            'item_name' => $productName,
                            'type_name' => $type,
                            'size_name' => $size,
                            'variant_label' => ! empty($parts) ? implode(' • ', $parts) : 'Стандартный вариант',
                        ];
                    })
                    ->values()
                    ->all();

                if (empty($variants)) {
                    $variants[] = [
                        'key' => $item->id . '_base',
                        'inventory_item_id' => $item->id,
                        'item_name' => $productName,
                        'type_name' => null,
                        'size_name' => null,
                        'variant_label' => 'Стандартный вариант',
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
        $this->selected[$key]['requested_qty'] = 1;
        $this->persistDraft();
    }

    public function increment(string $key): void
    {
        $current = (int) ($this->selected[$key]['requested_qty'] ?? 0);
        $this->selected[$key]['requested_qty'] = $current + 1;
        $this->persistDraft();
    }

    public function decrement(string $key): void
    {
        $current = (int) ($this->selected[$key]['requested_qty'] ?? 0);
        $next = max($current - 1, 0);

        if ($next <= 0) {
            unset($this->selected[$key]);
            $this->persistDraft();
            return;
        }

        $this->selected[$key]['requested_qty'] = $next;
        $this->persistDraft();
    }

    public function removeSelected(string $key): void
    {
        unset($this->selected[$key]);
        $this->persistDraft();
    }

    public function updatedSelected($value, string $name): void
    {
        if (! str_ends_with($name, '.requested_qty')) {
            return;
        }

        $key = explode('.', $name)[0] ?? null;

        if (! $key) {
            return;
        }

        $qty = max(0, (int) $value);

        if ($qty <= 0) {
            unset($this->selected[$key]);
        } else {
            $this->selected[$key]['requested_qty'] = $qty;
        }

        $this->persistDraft();
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

                        return str_contains($productName, $search)
                            || str_contains($variantText, $search);
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

        $this->clearDraft();
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
                'Добавьте хотя бы одну позицию'
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

            $this->resetErrorBag();
            $this->resetValidation();
            $this->clearDraft();

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
                'Попробуйте ещё раз через пару минут',
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
    <div class="w-full h-[73px] flex items-center justify-between px-[15px]">
        <button
            type="button"
            onclick="history.back()"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer bg-[#E1E1E1] backdrop-blur-md text-white transition-all duration-300 hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]" />
        </button>

        <span class="flex items-center justify-center text-[18px] leading-none">
            Заявка на инвентарь
        </span>

        <button
            type="button"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer bg-[#E1E1E1] backdrop-blur-md text-white transition-all duration-300 hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-magnifying-glass class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]" />
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

                if (this.nearBottom || current <= 8) {
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
                    <div class="mb-[20px]">
                        <h2 class="mb-[14px] text-[16px] font-medium text-[#213259]">
                            Что необходимо получить?
                        </h2>

                        <label class="block">
                            <span class="relative block">
                                <span class="pointer-events-none absolute left-[18px] top-1/2 -translate-y-1/2 text-black/35">
                                    <x-heroicon-o-magnifying-glass class="h-[18px] w-[18px]" />
                                </span>

                                <input
                                    type="text"
                                    wire:model.live.debounce.250ms="search"
                                    placeholder="Поиск по названию или варианту"
                                    class="h-[50px] w-full rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] pl-[46px] pr-[16px] text-[15px] text-[#213259] outline-none transition focus:border-[#D6D6D6] focus:bg-white focus:ring-0"
                                >
                            </span>
                        </label>
                    </div>

                    <div class="space-y-[14px]">
                        @forelse ($this->filteredCatalog as $product)
                            <section class="rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] p-[14px]">
                                <div class="mb-[12px] flex items-center justify-between gap-[12px]">
                                    <h2 class="min-w-0 truncate text-[16px] font-medium text-[#213259]">
                                        {{ $product['name'] }}
                                    </h2>

                                    @php
                                        $productQty = collect($product['variants'] ?? [])
                                            ->sum(fn ($variant) => (int) ($selected[$variant['key']]['requested_qty'] ?? 0));
                                    @endphp

                                    @if ($productQty > 0)
                                        <span class="shrink-0 rounded-full bg-white px-[10px] py-[4px] text-[12px] font-medium text-[#213259]">
                                            {{ $productQty }} шт.
                                        </span>
                                    @endif
                                </div>

                                <div class="space-y-[8px]">
                                    @foreach (($product['variants'] ?? []) as $variant)
                                        @php
                                            $key = $variant['key'];
                                            $requestedQty = (int) ($selected[$key]['requested_qty'] ?? 0);
                                            $isSelected = $requestedQty > 0;
                                        @endphp

                                        <div class="rounded-[18px] bg-white px-[12px] py-[12px]">
                                            <div class="flex items-center justify-between gap-[10px]">
                                                <div class="min-w-0 flex-1">
                                                    <div class="truncate text-[14px] font-medium text-[#213259]">
                                                        {{ $variant['variant_label'] }}
                                                    </div>
                                                </div>

                                                @if (! $isSelected)
                                                    <button
                                                        type="button"
                                                        wire:click="selectOne('{{ $key }}')"
                                                        class="flex h-[34px] shrink-0 items-center justify-center rounded-full bg-[#F4F7FB] px-[14px] text-[13px] font-medium text-[#213259] transition duration-200 hover:bg-[#EEF4F8] active:scale-[0.97]"
                                                    >
                                                        Добавить
                                                    </button>
                                                @else
                                                    <div class="flex shrink-0 items-center gap-[6px]">
                                                        <button
                                                            type="button"
                                                            wire:click="decrement('{{ $key }}')"
                                                            class="flex h-[32px] w-[32px] items-center justify-center rounded-full bg-[#F4F7FB] text-[18px] text-[#213259] transition active:scale-[0.96]"
                                                        >
                                                            −
                                                        </button>

                                                        <input
                                                            type="number"
                                                            min="0"
                                                            wire:model.live="selected.{{ $key }}.requested_qty"
                                                            class="h-[32px] w-[46px] rounded-full border-0 bg-[#F4F7FB] px-1 text-center text-[14px] font-medium text-[#213259] outline-none focus:ring-0"
                                                        >

                                                        <button
                                                            type="button"
                                                            wire:click="increment('{{ $key }}')"
                                                            class="flex h-[32px] w-[32px] items-center justify-center rounded-full bg-[#F4F7FB] text-[18px] text-[#213259] transition active:scale-[0.96]"
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
                            <div class="rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] px-[18px] py-[18px] text-center text-[15px] text-black/45">
                                Ничего не найдено
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
                    :disabled="empty($this->selectedSummary) && blank($search)"
                >
                    Сбросить
                </x-ui.button>
            </div>

            <div class="col-span-2">
                <x-ui.button
                    type="button"
                    variant="primary"
                    :progress="empty($this->selectedSummary) ? 0 : 100"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                    :disabled="empty($this->selectedSummary)"
                >
                    <span wire:loading.remove wire:target="submit">
                        {{ empty($this->selectedSummary)
                            ? 'Выберите'
                            : 'Отправить • ' . $this->totalSelectedQty . ' шт.' }}
                    </span>

                    <span
                        wire:loading
                        wire:target="submit"
                        class="inline-flex items-center gap-[2px]"
                    >
                        <span>Сохраняем</span>

                        <span class="inline-flex items-end leading-none">
                            <span class="animate-[dotFade_1.4s_infinite]">.</span>
                            <span class="animate-[dotFade_1.4s_infinite_0.2s]">.</span>
                            <span class="animate-[dotFade_1.4s_infinite_0.4s]">.</span>
                        </span>
                    </span>
                </x-ui.button>
            </div>
        </div>
    </div>
</div>
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
                    Заявка на инвентарь успешно отправлена
                </h1>

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
                        Понятно
                    </x-ui.button>
                </div>
            </div>
        </x-ui.bottom-sheet>
    </div>
</div>