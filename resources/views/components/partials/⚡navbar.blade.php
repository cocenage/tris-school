<?php

use Livewire\Component;

new class extends Component {
    //
};
?>

<nav class="w-full h-full p-[15px] relative">
    <svg width="0" height="0" class="absolute">
        <defs>
            <linearGradient id="nav-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%">
                    <animate attributeName="stop-color" values="#213259;#2D6494;#368DC4;#5BBEFF;#213259" dur="6s"
                        repeatCount="indefinite" />
                </stop>

                <stop offset="50%">
                    <animate attributeName="stop-color" values="#368DC4;#5BBEFF;#213259;#2D6494;#368DC4" dur="6s"
                        repeatCount="indefinite" />
                </stop>

                <stop offset="100%">
                    <animate attributeName="stop-color" values="#5BBEFF;#213259;#2D6494;#368DC4;#5BBEFF" dur="6s"
                        repeatCount="indefinite" />
                </stop>
            </linearGradient>
        </defs>
    </svg>

    <div class="flex items-center justify-between">

        <a href="{{ route('page-home') }}" class="group flex flex-col items-center justify-center gap-[5px]">

            <x-heroicon-s-home class="w-[20px] h-[20px] transition-all duration-300
                {{ request()->routeIs('page-home*')
    ? '[&>*]:fill-[url(#nav-gradient)]'
    : 'text-[#E1E1E1] group-hover:text-[#7D7D7D]'
                }}" />

            <span class="text-[10px] font-medium transition-all duration-300
                {{ request()->routeIs('page-home*')
    ? 'bg-[linear-gradient(135deg,#213259,#2D6494,#368DC4,#5BBEFF,#213259)] bg-[length:250%_250%] animate-[gradientOrbit_6s_ease-in-out_infinite] bg-clip-text text-transparent'
    : 'text-[#E1E1E1] group-hover:text-[#7D7D7D]'
                }}">
                Главная
            </span>
        </a>

        <a href="{{ route('page-checks') }}" class="group flex flex-col items-center justify-center gap-[5px]">

            <x-heroicon-s-clipboard-document-check class="w-[20px] h-[20px] transition-all duration-300
                {{ request()->routeIs('page-checks*')
    ? '[&>*]:fill-[url(#nav-gradient)]'
    : 'text-[#E1E1E1] group-hover:text-[#7D7D7D]'
                }}" />

            <span class="text-[10px] font-medium transition-all duration-300
                {{ request()->routeIs('page-checks*')
    ? 'bg-[linear-gradient(135deg,#213259,#2D6494,#368DC4,#5BBEFF,#213259)] bg-[length:250%_250%] animate-[gradientOrbit_6s_ease-in-out_infinite] bg-clip-text text-transparent'
    : 'text-[#E1E1E1] group-hover:text-[#7D7D7D]'
                }}">
                Проверки
            </span>
        </a>

        <a href="{{ route('page-applications') }}" class="group flex flex-col items-center justify-center gap-[5px]">

            <x-heroicon-s-clipboard-document-list class="w-[20px] h-[20px] transition-all duration-300
                {{ request()->routeIs('page-applications*')
    ? '[&>*]:fill-[url(#nav-gradient)]'
    : 'text-[#E1E1E1] group-hover:text-[#7D7D7D]'
                }}" />

            <span class="text-[10px] font-medium transition-all duration-300
                {{ request()->routeIs('page-applications*')
    ? 'bg-[linear-gradient(135deg,#213259,#2D6494,#368DC4,#5BBEFF,#213259)] bg-[length:250%_250%] animate-[gradientOrbit_6s_ease-in-out_infinite] bg-clip-text text-transparent'
    : 'text-[#E1E1E1] group-hover:text-[#7D7D7D]'
                }}">
                Заявки
            </span>
        </a>

        <a href="{{ route('page-profile') }}" class="group flex flex-col items-center justify-center gap-[5px]">

            <x-heroicon-s-user class="w-[20px] h-[20px] transition-all duration-300
                {{ request()->routeIs('page-profile*')
    ? '[&>*]:fill-[url(#nav-gradient)]'
    : 'text-[#E1E1E1] group-hover:text-[#7D7D7D]'
                }}" />

            <span class="text-[10px] font-medium transition-all duration-300
                {{ request()->routeIs('page-profile*')
    ? 'bg-[linear-gradient(135deg,#213259,#2D6494,#368DC4,#5BBEFF,#213259)] bg-[length:250%_250%] animate-[gradientOrbit_6s_ease-in-out_infinite] bg-clip-text text-transparent'
    : 'text-[#E1E1E1] group-hover:text-[#7D7D7D]'
                }}">
                Профиль
            </span>
        </a>

    </div>
</nav>