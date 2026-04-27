<?php

use App\Models\Instruction;
use App\Models\InstructionCategory;
use Livewire\Component;

new class extends Component {
    public string $search = '';

    public function getUncategorizedInstructionsProperty()
    {
        return Instruction::query()
            ->whereNull('instruction_category_id')
            ->where('status', 'published')
            ->where('is_public', true)
            ->when($this->search, function ($query) {
                $query->where(function ($query) {
                    $query
                        ->where('title', 'like', '%' . $this->search . '%')
                        ->orWhere('short_description', 'like', '%' . $this->search . '%');
                });
            })
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->get();
    }

    public function getCategoriesProperty()
    {
        return InstructionCategory::query()
            ->where('is_active', true)
            ->with(['instructions' => function ($query) {
                $query
                    ->where('status', 'published')
                    ->where('is_public', true)
                    ->when($this->search, function ($query) {
                        $query->where(function ($query) {
                            $query
                                ->where('title', 'like', '%' . $this->search . '%')
                                ->orWhere('short_description', 'like', '%' . $this->search . '%');
                        });
                    })
                    ->orderByDesc('is_featured')
                    ->orderBy('sort_order')
                    ->orderByDesc('published_at');
            }])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($category) => $category->instructions->isNotEmpty());
    }
};
?>

<x-slot:header>
    <livewire:search.search-bar />
</x-slot:header>

<section class="min-h-screen p-[20px]">
    <div class="mx-auto space-y-[20px]">

        @if ($this->uncategorizedInstructions->isNotEmpty())
            <div>
           
                  <h1 class="]">Инструкции</h1>

<div class="grid grid-cols-3 gap-[10px] pt-[20px]">
    @foreach ($this->uncategorizedInstructions as $instruction)
        <a
            href="{{ route('page-home.instructions.single', $instruction) }}"
            class="
                rounded-[30px]
                bg-[#E1E1E1]
                p-[15px]
                {{ $loop->first
                    ? 'col-span-2 aspect-[2/1]'
                    : 'aspect-square' }}
            "
        >
            <p class="text-[14px] font-medium leading-tight">
                {{ $instruction->title }}
            </p>
        </a>
    @endforeach
</div>
            </div>
        @endif

        @foreach ($this->categories as $category)
            <div class="pt-[10px]">
              
                <h1>   {{ $category->title }}</h1>

                <div class="grid grid-cols-3 gap-[10px] pt-[20px]">
                    @foreach ($category->instructions as $instruction)
                        <a
                            href="{{ route('page-home.instructions.single', $instruction) }}"
                            class="
                               aspect-square rounded-[30px] bg-[#E1E1E1] p-[12px]
                          
                            "
                        >
                            <div class="text-[13px] font-semibold leading-tight text-[#111111]">
                                {{ $instruction->title }}
                            </div>

                            @if ($instruction->short_description)
                                <div class="mt-[6px] line-clamp-3 text-[11px] leading-tight text-[#555555]">
                                    {{ $instruction->short_description }}
                                </div>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach

        @if (
            $this->uncategorizedInstructions->isEmpty()
            && $this->categories->isEmpty()
        )
            <div class="rounded-[28px] bg-white p-[24px] text-center">
                <div class="text-[18px] font-bold text-[#111111]">
                    Ничего не найдено
                </div>

                <div class="mt-[6px] text-[14px] text-[#777777]">
                    Попробуйте изменить запрос поиска.
                </div>
            </div>
        @endif

    </div>
</section>