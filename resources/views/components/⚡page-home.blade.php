<?php

use App\Models\InstructionCategory;
use Livewire\Component;

new class extends Component {
    public function getInstructionCategoriesProperty()
    {
        return InstructionCategory::query()
            ->where('is_active', true)
            ->withCount([
                'instructions' => function ($query) {
                    $query->where('status', 'published')
                        ->where('is_public', true);
                }
            ])
            ->orderBy('sort_order')
            ->limit(8)
            ->get();
    }
};
?>

{{-- PAGE HOME BLOCK: карточки инструкций --}}

<div class="px-[20px]">
    <div class="mb-3 flex items-center justify-between">
        <h2 class="text-[18px] font-semibold text-[#111827]">
            Инструкции
        </h2>

        <a
            href="{{ route('page-home.instructions') }}"
            class="text-[13px] font-medium text-[#6B7280]"
        >
            Все
        </a>
    </div>

    <div class="flex gap-3 overflow-x-auto pb-2 no-scrollbar">

        {{-- Все инструкции --}}
        <a
            href="{{ route('page-home.instructions') }}"
            class="relative flex h-[132px] w-[132px] shrink-0 flex-col justify-between overflow-hidden rounded-[30px] bg-[#111827] p-4 text-white shadow-sm active:scale-[0.98]"
        >
            <div class="absolute -right-5 -top-5 size-[70px] rounded-full bg-white/10"></div>
            <div class="absolute -bottom-7 -left-7 size-[90px] rounded-full bg-white/10"></div>

            <div class="relative flex size-[42px] items-center justify-center rounded-[16px] bg-white/15 text-[22px]">
                📚
            </div>

            <div class="relative">
                <div class="text-[15px] font-semibold leading-tight">
                    Все инструкции
                </div>

                <div class="mt-1 text-[12px] text-white/65">
                    Открыть базу
                </div>
            </div>
        </a>

        {{-- Категории --}}
        @foreach ($this->instructionCategories as $category)
            <a
                href="{{ route('page-home.instructions', ['category' => $category->id]) }}"
                class="relative flex h-[132px] w-[132px] shrink-0 flex-col justify-between overflow-hidden rounded-[30px] p-4 shadow-sm active:scale-[0.98]"
                style="background: {{ $category->color ?: '#EEF2FF' }};"
            >
                <div class="absolute -right-5 -top-5 size-[70px] rounded-full bg-white/35"></div>
                <div class="absolute -bottom-7 -left-7 size-[90px] rounded-full bg-white/30"></div>

                <div class="relative flex size-[42px] items-center justify-center rounded-[16px] bg-white/45 text-[22px]">
                    {{ $category->emoji ?: '📄' }}
                </div>

                <div class="relative">
                    <div class="line-clamp-2 text-[15px] font-semibold leading-tight text-[#111827]">
                        {{ $category->title }}
                    </div>

                    <div class="mt-1 text-[12px] font-medium text-[#4B5563]">
                        {{ $category->instructions_count }} статей
                    </div>
                </div>
            </a>
        @endforeach

    </div>
</div>