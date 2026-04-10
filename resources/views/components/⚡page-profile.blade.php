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

 <div class="bg-[#F6F6F6] rounded-[30px] overflow-hidden">
    <div class="px-[24px]">
        <hr class="border-0 h-px bg-[#E3E3E3]">
    </div>

    <a
        href="#"
        class="group flex items-center justify-between px-[24px] py-[18px] transition-colors duration-200 hover:bg-[#ECECEC] active:bg-[#E5E5E5]"
    >
        <div class="flex items-center gap-[16px] min-w-0">
            <svg
                class="w-[26px] h-[26px] text-[#1F1F1F] shrink-0 transition-transform duration-200 group-hover:scale-105"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <path d="M12 21s-6.716-4.35-9.193-8.062C.934 9.995 2.223 6.5 5.5 5.5c2.128-.65 4.07.19 5.15 1.72C11.73 5.69 13.672 4.85 15.8 5.5c3.277 1 4.566 4.495 2.693 7.438C18.716 16.65 12 21 12 21Z"/>
            </svg>

            <span class="text-[18px] font-medium text-[#1F1F1F]">
                Любимое
            </span>
        </div>

        <svg
            class="w-[18px] h-[18px] text-[#2A2A2A] shrink-0 transition-all duration-200 group-hover:translate-x-[2px]"
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            stroke-width="2.4"
        >
            <path
                d="M7 4l6 6-6 6"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </a>

    <div class="px-[24px]">
        <hr class="border-0 h-px bg-[#E3E3E3]">
    </div>

    <a
        href="#"
        class="group flex items-center justify-between px-[24px] py-[18px] transition-colors duration-200 hover:bg-[#ECECEC] active:bg-[#E5E5E5]"
    >
        <div class="flex items-center gap-[16px] min-w-0">
            <svg
                class="w-[26px] h-[26px] text-[#1F1F1F] shrink-0 transition-transform duration-200 group-hover:scale-105"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <rect x="6" y="4" width="12" height="16" rx="2"/>
                <path d="M9 8h6M9 12h6M9 16h4"/>
            </svg>

            <span class="text-[18px] font-medium text-[#1F1F1F]">
                История заказов
            </span>
        </div>

        <svg
            class="w-[18px] h-[18px] text-[#2A2A2A] shrink-0 transition-all duration-200 group-hover:translate-x-[2px]"
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            stroke-width="2.4"
        >
            <path
                d="M7 4l6 6-6 6"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </a>

    <div class="px-[24px]">
        <hr class="border-0 h-px bg-[#E3E3E3]">
    </div>

    <a
        href="#"
        class="group flex items-center justify-between px-[24px] py-[18px] transition-colors duration-200 hover:bg-[#ECECEC] active:bg-[#E5E5E5]"
    >
        <div class="flex items-center gap-[16px] min-w-0">
            <svg
                class="w-[26px] h-[26px] text-[#1F1F1F] shrink-0 transition-transform duration-200 group-hover:scale-105"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
            >
                <path d="M4 7h16M7 12h10M9 17h6" stroke-linecap="round"/>
            </svg>

            <span class="text-[18px] font-medium text-[#1F1F1F]">
                Мои предпочтения
            </span>
        </div>

        <svg
            class="w-[18px] h-[18px] text-[#2A2A2A] shrink-0 transition-all duration-200 group-hover:translate-x-[2px]"
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            stroke-width="2.4"
        >
            <path
                d="M7 4l6 6-6 6"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>
    </a>

    <div class="px-[24px]">
        <hr class="border-0 h-px bg-[#E3E3E3]">
    </div>
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