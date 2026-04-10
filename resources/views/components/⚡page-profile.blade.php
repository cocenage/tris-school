<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
        use WithFileUploads;

    public $avatar;
public function saveAvatar(): void
{
    $user = Auth::user();

    if (! $user || ! $this->avatar) {
        return;
    }

    $this->validate([
        'avatar' => ['required', 'image', 'max:5120'],
    ]);

    if (
        $user->telegram_avatar_path &&
        Storage::disk('public')->exists($user->telegram_avatar_path)
    ) {
        Storage::disk('public')->delete($user->telegram_avatar_path);
    }

    $path = $this->avatar->store('user-avatars', 'public');

    $user->update([
        'telegram_avatar_path' => $path,
    ]);

    $this->reset('avatar');
}
};
?>

<div class="bg-white w-full h-screen p-[15px]">
    @php
        $user = auth()->user();
            use Illuminate\Support\Str;
    @endphp

    <div class="flex items-center gap-[10px]">


<div x-data class="w-[80px] h-[80px] shrink-0">
    <input
        type="file"
        x-ref="avatarInput"
        class="hidden"
        accept="image/*"
        wire:model="avatar"
    >

    <button
        type="button"
        @click="$refs.avatarInput.click()"
        class="w-full h-full rounded-full overflow-hidden bg-[#E1E1E1] active:scale-[0.97] transition"
    >
        @if($avatar)
            <img
                src="{{ $avatar->temporaryUrl() }}"
                alt="Preview"
                class="w-full h-full object-cover"
            >
        @elseif(auth()->user()?->telegram_avatar_path)
            <img
                src="{{ Storage::url(auth()->user()->telegram_avatar_path) }}"
                alt="{{ auth()->user()->name }}"
                class="w-full h-full object-cover"
            >
        @else
            <div class="w-full h-full flex flex-col items-center justify-center">
                <div class="text-[24px] font-semibold text-[#666666]">
                    {{ mb_substr(auth()->user()?->name ?? 'U', 0, 1) }}
                </div>
                <div class="text-[10px] text-[#666666] mt-[2px]">
                    Загрузить
                </div>
            </div>
        @endif
    </button>

    @if($avatar)
        <button
            type="button"
            wire:click="saveAvatar"
            class="mt-2 px-3 h-9 rounded-full bg-black text-white text-sm"
        >
            Сохранить
        </button>
    @endif
</div>

        <div class="flex flex-col gap-[5px]">
            <span class="text-[20px] font-medium">
                {{ $user->name }}
            </span>

            <span class="text-[18px] opacity-50">
                @switch($user->role)
                    @case('admin')
                        Администратор
                        @break

                    @case('supervisor')
                        Супервайзер
                        @break

                    @case('cleaner')
                        Клинер
                        @break

                    @default
                        {{ $user->role }}
                @endswitch
            </span>
        </div>
    </div>

    <div class="bg-[#F8F7F5] rounded-[30px] mt-[30px]">
        <div href="" class="flex items-center p-[15px]">
             <x-heroicon-o-magnifying-glass class="w-[30px] h-[30px] stroke-[2]" />
             <div class="pl-[15px] w-full flex justify-between items-center">
                <p>Проверка</p>
<x-heroicon-o-magnifying-glass class="w-[15px] h-[15px] stroke-[2]" />
             </div>

        </div>
<hr class="w-full h-[1.5px]">
       <a href="" class="flex items-center p-[15px]">
             <x-heroicon-o-magnifying-glass class="w-[30px] h-[30px] stroke-[2]" />
             <div class="pl-[15px] w-full flex justify-between items-center">
                <p>Проверка</p>
<x-heroicon-o-magnifying-glass class="w-[15px] h-[15px] stroke-[2]" />
             </div>

        </a>
    </div>
    <div class="bg-[#F8F7F5] rounded-[30px] mt-[30px] overflow-hidden">
    <a href=""
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between border-b border-[#B9B2AD] py-[8px]">
            <div class="flex items-center gap-[10px]">
                <p class="text-[20px] font-semibold text-[#3B2F2A]">
                    Проверки
                </p>

                <div class="w-[26px] h-[26px] rounded-full bg-[#FF6432] flex items-center justify-center">
                    <span class="text-white text-[12px] font-semibold leading-none">10</span>
                </div>
            </div>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>

    <a href=""
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between border-b border-[#B9B2AD] py-[8px]">
            <p class="text-[20px] font-semibold text-[#3B2F2A]">
                Заявки
            </p>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>

    <a  href="/admin"
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between border-b border-[#B9B2AD] py-[8px]">
            <p class="text-[20px] font-semibold text-[#1DFF55]">
                админка
            </p>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>

    <a href="{{ route('page-profile.calendar') }}"
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between py-[8px]">
            <p class="text-[20px] font-semibold text-[#FF6432]">
                Календарь
            </p>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>

        <a href="{{ route('page-profile.weekend') }}"
       class="group flex items-center px-[20px] py-[20px] active:bg-black/5 transition-colors duration-150">
        <x-heroicon-o-calendar-days class="w-[28px] h-[28px] text-[#3B2F2A] shrink-0" />

        <div class="ml-[18px] flex-1 flex items-center justify-between py-[8px]">
            <p class="text-[20px] font-semibold text-[#FF6432]">
                Выходной деееень
            </p>

            <x-heroicon-o-chevron-right class="w-[22px] h-[22px] text-[#3B2F2A] transition-transform duration-150 group-active:translate-x-[2px]" />
        </div>
    </a>
</div>
</div>