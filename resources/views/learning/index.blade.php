@component('layouts.app', ['title' => 'Обучение'])
    <section class="mx-auto max-w-3xl space-y-5 p-5 sm:p-8">
        <a href="{{ route('page-home') }}" class="inline-flex min-h-11 items-center text-sm underline">Главная</a>
        <h1 class="text-2xl font-semibold">Обучение</h1>
        @if ($progress['total'])
            <article class="space-y-4 rounded-3xl bg-[#F3F4F6] p-5">
                <h2 class="text-xl font-semibold"><a href="{{ route('learning.show', \App\Services\Learning\LearningCatalog::COURSE_SLUG) }}">Опубликованные инструкции</a></h2>
                <p class="text-sm text-gray-600">Курс по действующим материалам TRIS. Содержание обновляется вместе с инструкциями.</p>
                @include('learning.progress')
                <a href="{{ route('learning.show', \App\Services\Learning\LearningCatalog::COURSE_SLUG) }}" class="inline-flex min-h-11 items-center rounded-full border border-gray-300 px-5">Открыть курс</a>
                @if ($progress['resume'])
                    <a href="{{ route('learning.lesson', ['course' => \App\Services\Learning\LearningCatalog::COURSE_SLUG, 'lesson' => $progress['resume']->slug]) }}" class="flex min-h-11 items-center justify-center rounded-full bg-[#213259] px-5 py-3 text-white">{{ $progress['completed'] || $progress['records']->isNotEmpty() ? 'Продолжить обучение' : 'Начать обучение' }}</a>
                @endif
            </article>
        @else
            <p class="rounded-3xl bg-gray-100 p-5">Курсы появятся после публикации учебных материалов.</p>
        @endif
    </section>
@endcomponent
