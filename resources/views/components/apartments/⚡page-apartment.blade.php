<?php

use App\Models\Apartment;
use App\Services\Apartments\ApartmentAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

new class extends Component {
    public Apartment $apartment;

    public function mount(Apartment $apartment, ApartmentAccessService $access): void
    {
        abort_unless($access->canView(auth()->user(), $apartment), 403);

        $this->apartment = $apartment;
    }

    public function getSectionsProperty(): Collection
    {
        $query = $this->apartment->informationSections()->with('attachments');

        if (! auth()->user()?->isAdmin()) {
            $query->where('is_visible', true);
        }

        return $query->get();
    }

    public function getUnassignedAttachmentsProperty(): Collection
    {
        return $this->apartment->informationAttachments()
            ->whereNull('information_section_id')
            ->get();
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
        <span class="max-w-[62%] truncate text-[18px] leading-none">{{ $apartment->name }}</span>
        <div class="h-[40px] w-[40px]"></div>
    </div>
</x-slot:header>

<div class="flex h-full min-h-0 flex-col bg-[#F4F7FB]">
    <div class="min-h-full flex-1 overflow-y-auto rounded-t-[38px] bg-white px-[15px] pb-[110px] pt-[18px]">
        <div class="overflow-hidden rounded-[30px] bg-[#F6F6F6]">
            @if($this->imageUrl($apartment->image))
                <img src="{{ $this->imageUrl($apartment->image) }}" alt="" class="h-[190px] w-full object-cover">
            @endif

            <div class="p-[17px]">
                <div class="flex items-start justify-between gap-[12px]">
                    <div class="min-w-0">
                        <h1 class="text-[26px] font-semibold leading-none">{{ $apartment->name }}</h1>
                        @if($apartment->address)
                            <p class="mt-[8px] text-[14px] leading-[1.35] text-black/50">{{ $apartment->address }}</p>
                        @endif
                    </div>

                    @if(auth()->user()?->isAdmin() && $apartment->information_status !== 'published')
                        <span class="shrink-0 rounded-full bg-white px-[9px] py-[6px] text-[11px] text-black/50">
                            {{ $apartment->information_status === 'archived' ? 'Архив' : 'Черновик' }}
                        </span>
                    @endif
                </div>

                @if($apartment->information_updated_at)
                    <p class="mt-[12px] text-[12px] text-black/35">
                        Обновлено {{ $apartment->information_updated_at->format('d.m.Y H:i') }}
                    </p>
                @endif
            </div>
        </div>

        <div class="mt-[14px] space-y-[10px]">
            @forelse($this->sections as $section)
                <section class="rounded-[28px] bg-[#F6F6F6] p-[17px]">
                    <div class="flex items-start gap-[10px]">
                        <div class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-white text-[#213259]">
                            <x-heroicon-o-information-circle class="h-[19px] w-[19px]" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-[8px]">
                                <h2 class="text-[18px] font-semibold leading-[1.15]">{{ $section->title }}</h2>
                                @if(auth()->user()?->isAdmin() && ! $section->is_visible)
                                    <span class="shrink-0 rounded-full bg-white px-[7px] py-[4px] text-[10px] text-black/45">Скрыт</span>
                                @endif
                            </div>
                            <p class="mt-[10px] whitespace-pre-line text-[14px] leading-[1.5] text-black/70">{{ $section->content }}</p>
                        </div>
                    </div>

                    @if($section->attachments->isNotEmpty())
                        <div class="mt-[14px] space-y-[7px] border-t border-black/5 pt-[12px]">
                            @foreach($section->attachments as $attachment)
                                <a href="{{ route('page-apartments.attachment', [$apartment, $attachment]) }}" target="_blank" class="flex items-center gap-[9px] rounded-[16px] bg-white px-[11px] py-[10px] text-[13px] text-[#213259]">
                                    <x-heroicon-o-paper-clip class="h-[17px] w-[17px] shrink-0" />
                                    <span class="min-w-0 flex-1 truncate">{{ $attachment->caption ?: $attachment->original_name }}</span>
                                    <x-heroicon-o-arrow-top-right-on-square class="h-[15px] w-[15px] shrink-0 text-black/35" />
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            @empty
                <div class="rounded-[28px] bg-[#F6F6F6] px-[18px] py-[23px] text-center">
                    <x-heroicon-o-information-circle class="mx-auto h-[29px] w-[29px] text-black/30" />
                    <p class="mt-[10px] text-[17px] font-semibold">Информация пока не заполнена</p>
                    <p class="mt-[6px] text-[13px] leading-[1.35] text-black/45">Если вы отвечаете за объект, добавьте инструкции через админ-панель.</p>
                </div>
            @endforelse

            @if($this->unassignedAttachments->isNotEmpty())
                <section class="rounded-[28px] bg-[#F6F6F6] p-[17px]">
                    <h2 class="text-[18px] font-semibold">Файлы</h2>
                    <div class="mt-[11px] space-y-[7px]">
                        @foreach($this->unassignedAttachments as $attachment)
                            <a href="{{ route('page-apartments.attachment', [$apartment, $attachment]) }}" target="_blank" class="flex items-center gap-[9px] rounded-[16px] bg-white px-[11px] py-[10px] text-[13px] text-[#213259]">
                                <x-heroicon-o-paper-clip class="h-[17px] w-[17px] shrink-0" />
                                <span class="min-w-0 flex-1 truncate">{{ $attachment->caption ?: $attachment->original_name }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
