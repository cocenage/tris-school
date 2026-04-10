
<nav class="bg-white border-t border-[#ECECEC] rounded-t-[32px]">
    <div class="flex items-center justify-between px-[12px] py-[14px]">

            <a
                href="{{ route('page-home') }}"
                class="flex-1 flex flex-col items-center justify-center gap-[6px] py-[14px] rounded-[28px]
                    {{ request()->routeIs('page-home*') ? 'text-[#111111]' : 'text-[#7A7A7A]' }}"
            >
                <x-heroicon-s-home class="w-[30px] h-[30px]" />
                <span class="text-[14px] font-medium">Главная</span>
            </a>

            <a
                href="{{ route('page-checks') }}"
                class="flex-1 flex flex-col items-center justify-center gap-[6px] py-[14px] rounded-[28px]
                    {{ request()->routeIs('page-checks*') ? 'text-[#111111]' : 'text-[#7A7A7A]' }}"
            >
                <x-heroicon-s-clipboard-document-check class="w-[30px] h-[30px]" />
                <span class="text-[14px] font-medium">Проверки</span>
            </a>

            <a
                href="{{ route('page-applications') }}"
                class="flex-1 flex flex-col items-center justify-center gap-[6px] py-[14px] rounded-[28px]
                    {{ request()->routeIs('page-applications*') ? 'text-[#111111]' : 'text-[#7A7A7A]' }}"
            >
                <x-heroicon-s-clipboard-document-list class="w-[30px] h-[30px]" />
                <span class="text-[14px] font-medium">Заявки</span>
            </a>

            <a
                href="{{ route('page-profile') }}"
                class="flex-1 flex flex-col items-center justify-center gap-[6px] py-[14px] rounded-[28px]
                    {{ request()->routeIs('page-profile*') ? 'text-[#111111]' : 'text-[#7A7A7A]' }}"
            >
                <x-heroicon-s-user class="w-[30px] h-[30px]" />
                <span class="text-[14px] font-medium">Профиль</span>
            </a>

        </div>
    </nav>
