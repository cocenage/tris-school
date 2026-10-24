<?php

use App\Models\Coaching;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Coaching $coaching;

    public function mount(Coaching $coaching): void
    {
        $user = Auth::user();
        abort_unless($user && ($user->role === 'admin'
            || ($user->role === 'supervisor' && (int) $coaching->supervisor_id === (int) $user->id)
            || ($user->role === 'cleaner' && (int) $coaching->user_id === (int) $user->id)), 403);
        $this->coaching = $coaching->load(['cleaner', 'supervisor', 'control', 'apartment']);
    }
};
?>

<x-slot:header>
    <div class="flex h-[70px] items-center gap-3 px-4 text-[#213259]">
        <a href="{{ route('page-coachings') }}" aria-label="Назад к коучингам" class="p-2"><x-heroicon-o-arrow-left class="size-5" /></a>
        <span role="heading" aria-level="1" class="text-[18px] leading-none">Коучинг</span>
    </div>
</x-slot:header>

<article class="h-full overflow-y-auto bg-white px-5 pb-10 pt-5">
    <div class="mx-auto max-w-2xl">
        <h2 class="text-xl font-semibold">{{ $coaching->cleaner?->name ?? 'Сотрудник недоступен' }}</h2>
        <dl class="mt-5 space-y-3 text-sm">
            <div><dt class="text-black/50">Дата коучинга</dt><dd class="mt-1">{{ ($coaching->conducted_date ?? $coaching->created_at)?->format('d.m.Y') ?? 'Не указана' }}</dd></div>
            <div><dt class="text-black/50">Супервайзер</dt><dd class="mt-1">{{ $coaching->supervisor?->name ?? 'Не указан' }}</dd></div>
            @if($coaching->apartment)<div><dt class="text-black/50">Квартира</dt><dd class="mt-1">{{ $coaching->apartment->name }}</dd></div>@endif
            @if($coaching->control)<div><dt class="text-black/50">Связанный контроль</dt><dd class="mt-1">{{ $coaching->control->name ?: 'Контроль #'.$coaching->control->id }}</dd></div>@endif
        </dl>
        <h3 class="mb-3 mt-8 font-semibold">Что разобрали</h3>
        <p class="whitespace-pre-wrap break-words leading-relaxed">{{ $coaching->description ?: 'Описание не сохранилось в исторической записи.' }}</p>
        <a href="{{ auth()->user()->role === 'admin' ? route('page-profile.employee-history', $coaching->user_id) : (auth()->id() === (int) $coaching->user_id ? route('page-profile.checks') : route('page-coachings')) }}" class="mt-8 inline-block text-sm text-[#213259] underline">Вернуться к истории</a>
    </div>
</article>
