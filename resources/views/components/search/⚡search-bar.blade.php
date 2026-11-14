<?php

use App\Services\Search\AcademySearch;
use App\Services\Search\AcademySearchStatistics;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public bool $opened = false;

    public string $query = '';

    #[Locked]
    public string $submittedQuery = '';

    public function search(): void
    {
        abort_unless(auth()->user()?->isApproved() && auth()->user()?->is_active, 403);
        $this->validate(['query' => ['required', 'string', 'min:2', 'max:120']]);
        $this->submittedQuery = app(AcademySearch::class)->normalize($this->query);
        unset($this->results);
        app(AcademySearchStatistics::class)->record($this->submittedQuery, count($this->results) > 0);
    }

    #[Computed]
    public function results(): array
    {
        return app(AcademySearch::class)->search(auth()->user(), $this->submittedQuery);
    }
};
?>

<div x-data="{ open: @entangle('opened') }" @keydown.escape.window="open = false" class="w-full">
    <button type="button" @click="open = true; $nextTick(() => $refs.searchInput.focus())" class="w-full" aria-label="Открыть поиск по Академии">
        <span class="m-[15px] flex h-[60px] items-center gap-[10px] rounded-full bg-[#E1E1E1] px-[20px]">
            <x-heroicon-o-magnifying-glass class="h-[30px] w-[30px] shrink-0 opacity-50" />
            <span class="text-[16px] text-[#555555]">Найти в Tris Academy</span>
        </span>
    </button>

    <div x-show="open" x-cloak @click="open = false" class="fixed inset-0 z-[90] bg-black/40" style="display: none;"></div>

    <div x-show="open" x-cloak x-trap.inert.noscroll="open" role="dialog" aria-modal="true" aria-label="Поиск по Академии"
        class="fixed left-1/2 top-[20px] z-[100] max-h-[90dvh] w-[calc(100%-30px)] max-w-[738px] -translate-x-1/2 overflow-y-auto rounded-[24px] bg-white p-[15px] shadow-xl"
        style="display: none;">
        <form wire:submit="search" class="space-y-3">
            <div class="flex items-center justify-between gap-3">
                <label for="academy-search-{{ $this->getId() }}" class="font-medium">Поиск по Академии</label>
                <button type="button" @click="open = false" class="rounded-full px-3 py-2 text-sm">Закрыть</button>
            </div>
            <div class="flex gap-2">
                <input id="academy-search-{{ $this->getId() }}" x-ref="searchInput" wire:model="query" type="search" minlength="2" maxlength="120"
                    placeholder="Инструкции, обучение, квартиры" class="min-w-0 flex-1 rounded-full bg-[#E1E1E1] px-4 py-3 text-[16px]" />
                <button type="submit" wire:loading.attr="disabled" wire:target="search" class="rounded-full bg-[#111111] px-4 py-3 text-white">Найти</button>
            </div>
            @error('query') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
        </form>
        <div wire:loading wire:target="search" class="pt-4 text-sm" role="status">Ищем…</div>
        <div wire:loading.remove wire:target="search" class="pt-4" aria-live="polite">
            @if ($submittedQuery !== '')
                <p class="mb-3 text-sm text-gray-600">Результаты для «{{ $submittedQuery }}». До 10 в каждом разделе.</p>
                @forelse (collect($this->results)->groupBy('section') as $section => $items)
                    <section class="mb-4">
                        <h2 class="mb-2 text-sm font-semibold text-gray-600">{{ $section }}</h2>
                        <ul class="space-y-1">
                            @foreach ($items as $item)
                                <li><a href="{{ $item['url'] }}" class="block rounded-xl px-3 py-3 hover:bg-gray-100 focus-visible:outline">{{ $item['title'] }}</a></li>
                            @endforeach
                        </ul>
                    </section>
                @empty
                    <p>Ничего не найдено. Попробуйте другое название или слово.</p>
                @endforelse
            @else
                <p class="text-sm text-gray-600">Введите хотя бы два символа и нажмите «Найти».</p>
            @endif
        </div>
    </div>
</div>
