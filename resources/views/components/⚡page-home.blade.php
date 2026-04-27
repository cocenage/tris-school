<?php

use App\Models\Instruction;
use Livewire\Component;

new class extends Component {
    public function getLatestInstructionsProperty()
    {
        return Instruction::query()
            ->with('category')
            ->where('status', 'published')
            ->where('is_public', true)
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get();
    }
};
?>
<x-slot:header>
    <livewire:search.search-bar />
</x-slot:header>
<div class="">
    <div class="flex gap-[10px] p-[20px] overflow-x-auto no-scrollbar">

        {{-- Все инструкции --}}
        <a
            href="{{ route('page-home.instructions') }}"
            class="w-[42vw] min-w-[150px] max-w-[200px] aspect-square shrink-0 flex flex-col justify-end overflow-hidden rounded-[30px] bg-[#E1E1E1] p-[15px]"
        >
            <div class="text-[16px] leading-tight ">
                Все инструкции
            </div>
        </a>

        {{-- Последние инструкции --}}
        @foreach ($this->latestInstructions as $instruction)
            <a
                href="{{ route('page-home.instructions.single', $instruction) }}"
                class="w-[42vw] min-w-[150px] max-w-[200px] aspect-square shrink-0 flex flex-col justify-end overflow-hidden rounded-[30px] bg-[#E1E1E1] p-[15px]"
            >
                <div>
                    @if ($instruction->category)
                        <div class="mb-1 text-[11px] font-medium text-[#4B5563]/70">
                            {{ $instruction->category->title }}
                        </div>
                    @endif

                    <div class="line-clamp-3 text-[16px] leading-tight">
                        {{ $instruction->title }}
                    </div>
                </div>
            </a>
        @endforeach

    </div>
</div>