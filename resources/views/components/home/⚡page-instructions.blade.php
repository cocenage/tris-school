<?php

use App\Models\Instruction;
use App\Models\InstructionCategory;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public string $search = '';

    #[Url(as: 'category')]
    public ?int $categoryId = null;

    public function getCategoriesProperty()
    {
        return InstructionCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function getInstructionsProperty()
    {
        return Instruction::query()
            ->with('category')
            ->where('status', 'published')
            ->where('is_public', true)
            ->when(
                $this->categoryId,
                fn ($query) => $query->where('instruction_category_id', $this->categoryId)
            )
            ->when($this->search, function ($query) {
                $query->where(function ($query) {
                    $query->where('title', 'like', '%' . $this->search . '%')
                        ->orWhere('short_description', 'like', '%' . $this->search . '%');
                });
            })
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->get();
    }

    public function selectCategory(?int $id): void
    {
        $this->categoryId = $id;
    }
};
?>

<section class="min-h-screen bg-[#F5F7FA] px-[15px] py-[18px]">
    <div class="mx-auto max-w-[820px] space-y-5">

        <a
            href="{{ route('page-home') }}"
            class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-[14px] font-medium text-[#4B5563] shadow-sm"
        >
            ← Назад
        </a>

        <div class="rounded-[32px] bg-white p-5 shadow-sm">
            <h1 class="text-[28px] font-semibold text-[#111827]">
                Инструкции
            </h1>

            <p class="mt-2 text-[15px] text-[#6B7280]">
                База знаний и полезные материалы
            </p>

            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Найти инструкцию..."
                class="mt-5 h-[52px] w-full rounded-[20px] border-0 bg-[#F3F5F8] px-4"
            >
        </div>

        <div class="flex gap-2 overflow-x-auto pb-1 no-scrollbar">
            <button
                wire:click="selectCategory(null)"
                class="shrink-0 rounded-full px-4 py-2
                {{ $categoryId === null ? 'bg-[#111827] text-white' : 'bg-white' }}"
            >
                Все
            </button>

            @foreach ($this->categories as $category)
                <button
                    wire:click="selectCategory({{ $category->id }})"
                    class="shrink-0 rounded-full px-4 py-2
                    {{ $categoryId === $category->id ? 'bg-[#111827] text-white' : 'bg-white' }}"
                >
                    {{ $category->emoji }} {{ $category->title }}
                </button>
            @endforeach
        </div>

        <div class="grid gap-3">
            @foreach ($this->instructions as $instruction)
                <a
                    href="{{ route('page-home.instructions.single', $instruction) }}"
                    class="rounded-[28px] bg-white p-4 shadow-sm"
                >
                    <div class="flex gap-4">
                        <div
                            class="flex size-[54px] shrink-0 items-center justify-center rounded-[20px] text-[24px]"
                            style="background: {{ $instruction->color ?: '#EEF2FF' }}"
                        >
                            {{ $instruction->emoji ?: '📄' }}
                        </div>

                        <div class="flex-1">
                            <h2 class="text-[16px] font-semibold text-[#111827]">
                                {{ $instruction->title }}
                            </h2>

                            @if ($instruction->short_description)
                                <p class="mt-1 text-[14px] text-[#6B7280]">
                                    {{ $instruction->short_description }}
                                </p>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>

    </div>
</section>