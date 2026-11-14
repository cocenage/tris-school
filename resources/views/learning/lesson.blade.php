@component('layouts.app', ['title' => $instruction->title])
    <div class="mx-auto max-w-3xl space-y-3 px-5 pt-5">
        <a href="{{ route('learning.show', \App\Services\Learning\LearningCatalog::COURSE_SLUG) }}" class="inline-flex min-h-11 items-center text-sm underline">К содержанию курса</a>
        @include('learning.progress')
    </div>
    <x-instruction-reader :instruction="$instruction" />
    <div class="mx-auto max-w-3xl px-5 pb-8">
        @if ($progress['records']->get($instruction->id)?->completed_at)
            <p class="py-3 font-semibold text-green-700">Урок пройден</p>
        @endif
        <form method="POST" action="{{ route('learning.complete', ['course' => \App\Services\Learning\LearningCatalog::COURSE_SLUG, 'lesson' => $instruction->slug]) }}">
            @csrf
            <button type="submit" class="flex min-h-12 w-full items-center justify-center rounded-full bg-[#213259] px-5 py-3 text-white">{{ $progress['records']->get($instruction->id)?->completed_at ? 'Продолжить' : 'Завершить урок и продолжить' }}</button>
        </form>
    </div>
@endcomponent
