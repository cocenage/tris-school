<?php

use App\Models\Coaching;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    public ?int $cleanerId = null;

    public string $conductedDate = '';

    public string $description = '';

    #[Locked]
    public ?int $createdCoachingId = null;

    public function mount(): void
    {
        $this->authorizeCreation();
        $this->conductedDate = today()->toDateString();
    }

    protected function authorizeCreation(): void
    {
        abort_unless(in_array(Auth::user()?->role, ['admin', 'supervisor'], true) && Auth::user()?->status === 'approved', 403);
    }

    public function getCleanersProperty()
    {
        return User::query()->activeStaff()->where('role', 'cleaner')->orderBy('name')->get(['id', 'name']);
    }

    public function save(): void
    {
        $this->authorizeCreation();

        if ($this->createdCoachingId !== null) {
            $this->redirectRoute('page-coachings.show', ['coaching' => $this->createdCoachingId]);

            return;
        }

        $this->description = trim($this->description);
        $data = $this->validate([
            'cleanerId' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'cleaner')->where('status', 'approved')->where('is_active', true)],
            'conductedDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:10000'],
        ], [
            'cleanerId.required' => 'Выберите сотрудника.',
            'cleanerId.exists' => 'Выберите действующего клинера из списка.',
            'conductedDate.required' => 'Укажите дату проведённого коучинга.',
            'conductedDate.date_format' => 'Укажите корректную дату.',
            'conductedDate.before_or_equal' => 'Дата проведённого коучинга не может быть в будущем.',
            'description.required' => 'Опишите, что вы разобрали с сотрудником.',
            'description.max' => 'Описание должно быть не длиннее 10 000 символов.',
        ]);

        $record = Coaching::create([
            'user_id' => $data['cleanerId'],
            'supervisor_id' => Auth::id(),
            'inspection_date' => $data['conductedDate'],
            'reason' => $data['description'],
        ]);
        $this->createdCoachingId = $record->id;
        $this->redirectRoute('page-coachings.show', ['coaching' => $record->id]);
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] items-center gap-3 px-4 text-[#213259]">
        <a href="{{ route('page-checks') }}" aria-label="Назад" class="p-2"><x-heroicon-o-arrow-left class="size-5" /></a>
        <span role="heading" aria-level="1" class="text-[18px] leading-none">Новый коучинг</span>
    </div>
</x-slot:header>

<div class="h-full overflow-y-auto bg-white px-5 pb-10 pt-5">
    <form wire:submit="save" class="mx-auto max-w-xl space-y-6">
        <p class="text-sm leading-relaxed text-black/55">Запишите коучинг, который вы уже провели с сотрудником.</p>
        <div>
            <label for="coaching-cleaner" class="mb-2 block font-medium">Сотрудник</label>
            <select id="coaching-cleaner" wire:model="cleanerId" class="min-h-12 w-full rounded-2xl border border-black/15 bg-white px-3">
                <option value="">Выберите сотрудника</option>
                @foreach($this->cleaners as $cleaner)
                    <option value="{{ $cleaner->id }}">{{ $cleaner->name }}</option>
                @endforeach
            </select>
            @error('cleanerId') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="coaching-date" class="mb-2 block font-medium">Дата проведения коучинга</label>
            <input id="coaching-date" type="date" wire:model="conductedDate" max="{{ today()->toDateString() }}" class="min-h-12 w-full min-w-0 rounded-2xl border border-black/15 bg-white px-3">
            @error('conductedDate') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="coaching-description" class="mb-2 block font-medium">Что отработали</label>
            <textarea id="coaching-description" wire:model="description" rows="8" class="w-full rounded-2xl border border-black/15 p-3 leading-relaxed" placeholder="Что обсудили, что показали и к чему пришли"></textarea>
            @error('description') <p role="alert" class="mt-2 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="min-h-12 w-full rounded-full bg-[#213259] px-5 font-semibold text-white disabled:opacity-50">
            <span wire:loading.remove wire:target="save">Сохранить коучинг</span>
            <span wire:loading wire:target="save">Сохраняем…</span>
        </button>
        <a href="{{ route('page-coachings.history') }}" class="flex min-h-12 items-center justify-center text-sm font-medium text-[#213259] underline">История коучингов</a>
    </form>
</div>
