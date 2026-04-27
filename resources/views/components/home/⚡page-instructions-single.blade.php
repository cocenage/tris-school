<?php

use App\Models\Instruction;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

new class extends Component {
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

    public function getTocProperty(): array
    {
        return collect($this->instruction->blocks ?? [])
            ->map(function ($block, $index) {
                $type = $block['type'] ?? null;
                $data = $block['data'] ?? [];

                $title = $data['title'] ?? null;

                if (! $title || ! in_array($type, ['text', 'hero', 'steps', 'checklist', 'tips', 'faq', 'links'])) {
                    return null;
                }

                return [
                    'id' => 'section-' . $index,
                    'title' => $title,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function imageUrl($image): ?string
    {
        if (is_array($image)) {
            $image = collect($image)->first();
        }

        if (! $image) {
            return null;
        }

        return Storage::disk('public')->url($image);
    }
};
?>

<x-slot:header>
    <livewire:search.search-bar />
</x-slot:header>

<section class="min-h-screen bg-white px-[18px] py-[24px]">
    <article class="mx-auto max-w-[1120px] pb-[120px]">

        <a
            href="{{ route('page-home.instructions') }}"
            class="mb-[34px] inline-flex text-[15px] font-medium text-[#6B7280]"
        >
            ← Все инструкции
        </a>

        <header class="max-w-[860px]">
            @if ($instruction->published_at)
                <div class="mb-[22px] text-[15px] text-[#8A8F98]">
                    {{ $instruction->published_at->translatedFormat('j F, Y') }}
                </div>
            @endif

            <h1 class="article-title">
                {{ $instruction->title }}
            </h1>

            @if ($instruction->short_description)
                <p class="article-lead">
                    {{ $instruction->short_description }}
                </p>
            @endif
        </header>

        @if ($instruction->cover_image)
            @php
                $coverUrl = $this->imageUrl($instruction->cover_image);
            @endphp

            @if ($coverUrl)
                <figure class="article-image-block article-cover">
                    <div class="article-image-frame">
                        <img
                            src="{{ $coverUrl }}"
                            alt="{{ $instruction->title }}"
                        >

                        <a
                            href="{{ $coverUrl }}"
                            target="_blank"
                            class="article-image-open"
                            aria-label="Открыть изображение"
                        >
                            ↗
                        </a>
                    </div>
                </figure>
            @endif
        @endif

        <div class="mt-[56px] grid gap-[64px] lg:grid-cols-[minmax(0,740px)_250px]">

            <main class="article-body">
                @forelse (($instruction->blocks ?? []) as $index => $block)
                    @php
                        $type = $block['type'] ?? null;
                        $data = $block['data'] ?? [];
                        $sectionId = 'section-' . $index;
                    @endphp

                    @if ($type === 'hero')
                        <section id="{{ $sectionId }}" class="article-intro">
                            @if (!empty($data['badge']))
                                <div class="article-badge">
                                    {{ $data['badge'] }}
                                </div>
                            @endif

                            @if (!empty($data['title']))
                                <h2>{{ $data['title'] }}</h2>
                            @endif

                            @if (!empty($data['description']))
                                <p>{{ $data['description'] }}</p>
                            @endif
                        </section>
                    @endif

                    @if ($type === 'text')
                        <section id="{{ $sectionId }}">
                            @if (!empty($data['title']))
                                <h2>{{ $data['title'] }}</h2>
                            @endif

                            @if (!empty($data['content']))
                                {!! $data['content'] !!}
                            @endif
                        </section>
                    @endif

                    @if ($type === 'warning')
                        @php
                            $warningType = $data['type'] ?? 'warning';

                            $noteClass = match ($warningType) {
                                'info' => 'article-note-info',
                                'success' => 'article-note-success',
                                'danger' => 'article-note-danger',
                                default => 'article-note-warning',
                            };
                        @endphp

                        <aside class="article-note {{ $noteClass }}">
                            <strong>{{ $data['title'] ?? 'Важно' }}</strong>

                            @if (!empty($data['content']))
                                <p>{{ $data['content'] }}</p>
                            @endif
                        </aside>
                    @endif

                    @if ($type === 'steps')
                        <section id="{{ $sectionId }}">
                            <h2>{{ $data['title'] ?? 'Пошаговая инструкция' }}</h2>

                            <div class="article-steps">
                                @foreach (($data['items'] ?? []) as $stepIndex => $item)
                                    <div class="article-step">
                                        <h3>
                                            Шаг {{ $stepIndex + 1 }}. {{ $item['title'] ?? '' }}
                                        </h3>

                                        @if (!empty($item['text']))
                                            <p>{{ $item['text'] }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($type === 'checklist')
                        <section id="{{ $sectionId }}">
                            <h2>{{ $data['title'] ?? 'Проверьте себя' }}</h2>

                            <ul>
                                @foreach (($data['items'] ?? []) as $item)
                                    <li>{{ $item['text'] ?? '' }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($type === 'tips')
                        <aside id="{{ $sectionId }}" class="article-note article-note-success">
                            <strong>{{ $data['title'] ?? 'Полезные советы' }}</strong>

                            <ul>
                                @foreach (($data['items'] ?? []) as $item)
                                    <li>{{ $item['text'] ?? '' }}</li>
                                @endforeach
                            </ul>
                        </aside>
                    @endif

                    @if ($type === 'faq')
                        <section id="{{ $sectionId }}">
                            <h2>{{ $data['title'] ?? 'Частые вопросы' }}</h2>

                            <div class="article-faq">
                                @foreach (($data['items'] ?? []) as $item)
                                    <details>
                                        <summary>{{ $item['question'] ?? '' }}</summary>

                                        @if (!empty($item['answer']))
                                            <p>{{ $item['answer'] }}</p>
                                        @endif
                                    </details>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($type === 'image' && !empty($data['image']))
                        @php
                            $imageUrl = $this->imageUrl($data['image']);
                        @endphp

                        @if ($imageUrl)
                            <figure class="article-image-block">
                                <div class="article-image-frame">
                                    <img
                                        src="{{ $imageUrl }}"
                                        alt="{{ $data['caption'] ?? $instruction->title }}"
                                    >

                                    <a
                                        href="{{ $imageUrl }}"
                                        target="_blank"
                                        class="article-image-open"
                                        aria-label="Открыть изображение"
                                    >
                                        ↗
                                    </a>
                                </div>

                                @if (!empty($data['caption']))
                                    <figcaption>
                                        {{ $data['caption'] }}
                                    </figcaption>
                                @endif
                            </figure>
                        @endif
                    @endif

                    @if ($type === 'video')
                        <section>
                            <h2>{{ $data['title'] ?? 'Видео' }}</h2>

                            @if (!empty($data['url']))
                                <a href="{{ $data['url'] }}" target="_blank" class="article-button">
                                    Открыть видео →
                                </a>
                            @endif
                        </section>
                    @endif

                    @if ($type === 'links')
                        <section id="{{ $sectionId }}">
                            <h2>{{ $data['title'] ?? 'Смотрите также' }}</h2>

                            <div class="article-links">
                                @foreach (($data['items'] ?? []) as $item)
                                    <a href="{{ $item['url'] ?? '#' }}">
                                        {{ $item['label'] ?? 'Ссылка' }}
                                        <span>→</span>
                                    </a>
                                @endforeach
                            </div>
                        </section>
                    @endif

                @empty
                    <p>Контент появится после заполнения в админке.</p>
                @endforelse
            </main>

            @if (count($this->toc))
                <aside class="hidden lg:block">
                    <div class="article-toc">
                        <div class="article-toc-title">
                            Содержание
                        </div>

                        <nav>
                            @foreach ($this->toc as $item)
                                <a href="#{{ $item['id'] }}">
                                    {{ $item['title'] }}
                                </a>
                            @endforeach
                        </nav>
                    </div>
                </aside>
            @endif

        </div>
    </article>
</section>

<style>
    html {
        scroll-behavior: smooth;
    }

    .article-title {
        color: #111827;
        font-size: clamp(38px, 6vw, 64px);
        line-height: 1.03;
        font-weight: 600;
        letter-spacing: -0.055em;
    }

    .article-lead {
        max-width: 760px;
        margin-top: 28px;
        color: #374151;
        font-size: clamp(19px, 2.4vw, 23px);
        line-height: 1.55;
        letter-spacing: -0.015em;
    }

    .article-body {
        max-width: 740px;
        color: #1f2933;
        font-size: 18px;
        line-height: 1.72;
        letter-spacing: -0.005em;
    }

    .article-body section {
        margin-bottom: 56px;
        scroll-margin-top: 28px;
    }

    .article-body h2 {
        margin: 0 0 20px;
        color: #111827;
        font-size: 32px;
        line-height: 1.18;
        font-weight: 600;
        letter-spacing: -0.025em;
    }

    .article-body h3 {
        margin: 36px 0 14px;
        color: #111827;
        font-size: 24px;
        line-height: 1.25;
        font-weight: 600;
        letter-spacing: -0.018em;
    }

    .article-body p {
        margin: 0 0 20px;
        color: #374151;
    }

    .article-body ul,
    .article-body ol {
        margin: 18px 0 28px;
        padding-left: 26px;
        color: #374151;
    }

    .article-body li {
        margin: 10px 0;
        padding-left: 4px;
    }

    .article-body a {
        color: #2563eb;
        text-decoration: none;
        border-bottom: 1px solid rgba(37, 99, 235, 0.35);
    }

    .article-intro {
        padding: 0;
    }

    .article-intro p {
        font-size: 20px;
        line-height: 1.6;
    }

    .article-badge {
        display: inline-flex;
        margin-bottom: 18px;
        padding: 7px 12px;
        border-radius: 999px;
        background: #f3f4f6;
        color: #6b7280;
        font-size: 14px;
        font-weight: 500;
    }

    .article-note {
        margin: 44px 0 56px;
        padding: 24px 28px;
        border-radius: 24px;
    }

    .article-note strong {
        display: block;
        margin-bottom: 8px;
        font-size: 18px;
        line-height: 1.35;
        font-weight: 650;
    }

    .article-note p {
        margin: 0;
        color: inherit;
    }

    .article-note ul {
        margin: 12px 0 0;
    }

    .article-note-warning {
        background: #fff3bf;
        color: #5f4600;
    }

    .article-note-info {
        background: #eaf3ff;
        color: #123c69;
    }

    .article-note-success {
        background: #eaf8ef;
        color: #14532d;
    }

    .article-note-danger {
        background: #fff0f0;
        color: #7f1d1d;
    }

    .article-steps {
        margin-top: 26px;
    }

    .article-step {
        margin-bottom: 36px;
    }

    .article-step h3 {
        margin-top: 0;
    }

    .article-image-block {
        margin: 52px 0 60px;
    }

    .article-cover {
        margin-top: 46px;
        margin-bottom: 0;
    }

    .article-image-frame {
        position: relative;
        display: flex;
        min-height: 320px;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        padding: 34px;
        border-radius: 36px;
        background: #d8d8d8;
    }

    .article-image-frame img {
        display: block;
        width: auto;
        max-width: 100%;
        max-height: 640px;
        height: auto;
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
    }

    .article-image-open {
        position: absolute;
        left: 32px;
        bottom: 32px;
        display: flex;
        width: 34px;
        height: 34px;
        align-items: center;
        justify-content: center;
        border: 0 !important;
        border-radius: 6px;
        background: rgba(17, 24, 39, 0.28);
        color: white !important;
        font-size: 18px;
        text-decoration: none;
    }

    .article-image-block figcaption {
        margin-top: 12px;
        color: #8a8f98;
        font-size: 14px;
        line-height: 1.45;
    }

    .article-faq {
        overflow: hidden;
        border: 1px solid #e5e7eb;
        border-radius: 24px;
    }

    .article-faq details {
        padding: 21px 24px;
        border-bottom: 1px solid #e5e7eb;
        background: #fff;
    }

    .article-faq details:last-child {
        border-bottom: 0;
    }

    .article-faq summary {
        cursor: pointer;
        list-style: none;
        color: #111827;
        font-size: 18px;
        font-weight: 600;
    }

    .article-faq summary::-webkit-details-marker {
        display: none;
    }

    .article-faq p {
        margin: 14px 0 0;
        font-size: 16px;
        line-height: 1.65;
    }

    .article-links {
        display: grid;
        gap: 12px;
    }

    .article-links a {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 18px 20px;
        border: 0;
        border-radius: 18px;
        background: #f5f7fa;
        color: #111827;
        font-size: 16px;
        font-weight: 550;
    }

    .article-button {
        display: inline-flex;
        margin-top: 6px;
        padding: 13px 18px;
        border: 0 !important;
        border-radius: 999px;
        background: #111827;
        color: white !important;
        font-size: 15px;
        font-weight: 600;
    }

    .article-toc {
        position: sticky;
        top: 28px;
        padding-top: 4px;
    }

    .article-toc-title {
        margin-bottom: 14px;
        color: #111827;
        font-size: 15px;
        font-weight: 650;
    }

    .article-toc nav {
        display: grid;
        gap: 11px;
    }

    .article-toc a {
        color: #6b7280;
        font-size: 14px;
        line-height: 1.35;
        text-decoration: none;
    }

    .article-toc a:hover {
        color: #111827;
    }

    @media (max-width: 768px) {
        .article-body {
            font-size: 17px;
            line-height: 1.68;
        }

        .article-body section {
            margin-bottom: 44px;
        }

        .article-body h2 {
            font-size: 28px;
        }

        .article-body h3 {
            font-size: 21px;
        }

        .article-image-frame {
            min-height: 220px;
            padding: 22px;
            border-radius: 28px;
        }

        .article-image-open {
            left: 20px;
            bottom: 20px;
        }

        .article-note {
            margin: 36px 0 44px;
            padding: 22px;
            border-radius: 22px;
        }
    }
</style>