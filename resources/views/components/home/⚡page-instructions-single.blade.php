<?php

use App\Models\Instruction;
use Livewire\Component;

new class extends Component
{
    public Instruction $instruction;

    public function mount(Instruction $instruction): void
    {
        abort_unless(
            $instruction->status === 'published' && $instruction->is_public,
            404
        );

        $this->instruction = $instruction;
        $this->instruction->increment('views_count');
    }

};
?>

<x-slot:header>
        <div class="w-full h-[73px] flex items-center justify-between px-[15px]">
     <button
            type="button"
            onclick="history.back()"
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer
                   bg-[#E1E1E1] backdrop-blur-md
                   text-white
                   transition-all duration-300
                   hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-arrow-left
                class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]"
            />
        </button>
        <span class="flex items-center justify-center text-[18px] leading-none">
            тут нужно сделать новый app
        </span>

     <button
            type="button"
     
            class="flex h-[40px] min-w-[40px] items-center justify-center rounded-full group cursor-pointer
                   bg-[#E1E1E1] backdrop-blur-md
                   text-white
                   transition-all duration-300
                   hover:bg-[#7D7D7D]"
        >
            <x-heroicon-o-magnifying-glass
                class="h-[20px] w-[20px] stroke-[2.4] group-active:scale-[0.95]"
            />
        </button>
    </div>
</x-slot:header>
<x-instruction-reader :instruction="$instruction" />
