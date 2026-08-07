<?php

use App\Services\Apartments\ApartmentAccessService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function getApartmentsProperty()
    {
        $query = app(ApartmentAccessService::class)
            ->visibleQuery(auth()->user())
            ->withCount(['informationSections as visible_sections_count' => function ($sections): void {
                if (! auth()->user()?->isAdmin()) {
                    $sections->where('is_visible', true);
                }
            }])
            ->when(trim($this->search) !== '', function ($query): void {
                $search = '%' . addcslashes(mb_strtolower(trim($this->search)), '%_') . '%';

                $query->where(function ($nested) use ($search): void {
                    $nested->whereRaw('LOWER(name) LIKE ? ESCAPE \'\\\'', [$search])
                        ->orWhereRaw('LOWER(COALESCE(address, \'\')) LIKE ? ESCAPE \'\\\'', [$search]);
                });
            });

        if (! auth()->user()?->isAdmin()) {
            $query->where('information_status', 'published');
        }

        return $query
            ->orderBy('name')
            ->paginate(12);
    }

    public function imageUrl(?string $path): ?string
    {
        return filled($path) ? Storage::disk('public')->url($path) : null;
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] w-full items-center justify-between px-[15px]">
        <button type="button" onclick="history.back()" class="flex h-[40px] w-[40px] items-center justify-center rounded-full bg-[#E9E9E9]">
            <x-heroicon-o-arrow-left class="h-[20px] w-[20px] stroke-[2.4]" />
        </button>

        <span class="text-[18px] leading-none">Квартиры</span>
        <div class="h-[40px] w-[40px]"></div>
    </div>
</x-slot:header>

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="min-h-full flex-1 overflow-y-auto rounded-t-[38px] bg-white px-[15px] pb-[110px] pt-[20px]">
        <div class="mb-[18px]">
            <h1 class="text-[30px] font-semibold leading-none">Квартиры</h1>
            <p class="mt-[8px] text-[14px] leading-[1.35] text-black/45">
                Доступные рабочие объекты и актуальные инструкции по ним.
            </p>
        </div>

        <div class="mb-[16px]">
            <label for="apartment-search" class="sr-only">Поиск квартир</label>
            <div class="relative">
                <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-[15px] top-1/2 h-[19px] w-[19px] -translate-y-1/2 text-black/35" />
                <input
                    id="apartment-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Название или адрес"
                    class="h-[52px] w-full rounded-[22px] bg-[#F6F6F6] pl-[44px] pr-[15px] text-[16px] outline-none focus:ring-2 focus:ring-[#213259]/15"
                >
            </div>
        </div>

        <div class="space-y-[10px]">
            @forelse($this->apartments as $apartment)
                <a href="{{ route('page-apartments.show', $apartment) }}" class="block rounded-[28px] bg-[#F6F6F6] p-[14px] transition active:scale-[0.99]">
                    <div class="flex items-center gap-[13px]">
                        @if($this->imageUrl($apartment->image))
                            <img src="{{ $this->imageUrl($apartment->image) }}" alt="" class="h-[66px] w-[66px] shrink-0 rounded-[20px] object-cover">
                        @else
                            <div class="flex h-[66px] w-[66px] shrink-0 items-center justify-center rounded-[20px] bg-white text-black/35">
                                <x-heroicon-o-home-modern class="h-[27px] w-[27px]" />
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-[8px]">
                                <h2 class="truncate text-[18px] font-semibold">{{ $apartment->name }}</h2>
                                @if(auth()->user()?->isAdmin() && $apartment->information_status !== 'published')
                                    <span class="shrink-0 rounded-full bg-white px-[8px] py-[4px] text-[11px] text-black/50">
                                        {{ $apartment->information_status === 'archived' ? 'Архив' : 'Черновик' }}
                                    </span>
                                @endif
                            </div>

                            <p class="mt-[5px] line-clamp-2 text-[13px] leading-[1.25] text-black/45">
                                {{ $apartment->address ?: 'Адрес пока не указан' }}
                            </p>

                            <p class="mt-[8px] text-[12px] text-black/35">
                                {{ $apartment->visible_sections_count }} {{ trans_choice('раздел|раздела|разделов', $apartment->visible_sections_count) }}
                            </p>
                        </div>

                        <x-heroicon-o-chevron-right class="h-[19px] w-[19px] shrink-0 text-black/30" />
                    </div>
                </a>
            @empty
                <div class="rounded-[28px] bg-[#F6F6F6] px-[18px] py-[24px] text-center">
                    <x-heroicon-o-home-modern class="mx-auto h-[29px] w-[29px] text-black/30" />
                    <p class="mt-[10px] text-[17px] font-semibold">Квартиры не найдены</p>
                    <p class="mt-[6px] text-[13px] leading-[1.35] text-black/45">Проверьте запрос или обратитесь к руководителю.</p>
                </div>
            @endforelse
        </div>

        @if($this->apartments->hasPages())
            <div class="mt-[18px]">{{ $this->apartments->links() }}</div>
        @endif
    </div>
</div>
