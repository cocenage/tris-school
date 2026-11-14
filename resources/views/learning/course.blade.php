@component('layouts.app', ['title' => 'Опубликованные инструкции'])
    <section class="mx-auto max-w-3xl space-y-5 p-5 sm:p-8">
        <a href="{{ route('learning.index') }}" class="inline-flex min-h-11 items-center text-sm underline">Все курсы</a>
        <h1 class="text-2xl font-semibold">Опубликованные инструкции</h1>
        @include('learning.progress')
        @if ($progress['resume'])
            <a href="{{ route('learning.lesson', ['course' => \App\Services\Learning\LearningCatalog::COURSE_SLUG, 'lesson' => $progress['resume']->slug]) }}" class="flex min-h-11 items-center justify-center rounded-full bg-[#213259] px-5 py-3 text-white">Продолжить обучение</a>
        @endif
        <ol class="space-y-3">
            @forelse ($lessons as $lesson)
                <li>
                    <a href="{{ route('learning.lesson', ['course' => \App\Services\Learning\LearningCatalog::COURSE_SLUG, 'lesson' => $lesson->slug]) }}" class="block min-h-11 space-y-2 rounded-3xl border border-gray-200 p-5">
                        <span class="block font-semibold">{{ $lesson->title }}</span>
                        @if ($lesson->short_description)<span class="block text-sm text-gray-600">{{ $lesson->short_description }}</span>@endif
                        <span class="block text-sm">{{ $progress['records']->get($lesson->id)?->completed_at ? 'Пройдено' : 'Открыть урок' }}</span>
                    </a>
                </li>
            @empty
                <li>Учебные материалы пока не опубликованы.</li>
            @endforelse
        </ol>
    </section>
@endcomponent
