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
                $title = trim($data['title'] ?? '');

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

        return $image ? Storage::disk('public')->url($image) : null;
    }
};
?>

<x-slot:header>
    <livewire:search.search-bar />
</x-slot:header>

<section
    x-data="{ imageOpen: false, imageSrc: '', imageAlt: '' }"
    class="min-h-screen bg-white px-[17px] py-[22px]"
>
    <article class="mx-auto max-w-[760px] pb-[120px]">

        <a
            href="{{ route('page-home.instructions') }}"
            class="mb-[24px] inline-flex items-center gap-2 text-[15px] font-medium text-[#5F6673]"
        >
            ↖ Полезные материалы
        </a>

        <header>
            <div class="mb-[18px] text-[15px] leading-none text-[#4B5563]">
                Обновлено {{ $instruction->updated_at->translatedFormat('j F, Y') }}
            </div>

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
                <figure class="article-cover">
                    <button
                        type="button"
                        class="article-cover-button"
                        @click="imageOpen = true; imageSrc = '{{ $coverUrl }}'; imageAlt = '{{ e($instruction->title) }}'"
                    >
                        <img src="{{ $coverUrl }}" alt="{{ $instruction->title }}">
                    </button>
                </figure>
            @endif
        @endif

        @if (count($this->toc))
            <div class="article-toc-mobile">
                <div class="article-toc-title">Содержание</div>

                <nav>
                    @foreach ($this->toc as $item)
                        <a href="#{{ $item['id'] }}">
                            {{ $item['title'] }}
                        </a>
                    @endforeach
                </nav>
            </div>
        @endif

        <main class="article-body">
            @forelse (($instruction->blocks ?? []) as $index => $block)
                @php
                    $type = $block['type'] ?? null;
                    $data = $block['data'] ?? [];
                    $sectionId = 'section-' . $index;
                    $title = trim($data['title'] ?? '');
                @endphp

                @if ($type === 'hero')
                    <section id="{{ $sectionId }}" class="article-section">
                        @if ($title)
                            <h2>{{ $title }}</h2>
                        @endif

                        @if (!empty($data['description']))
                            <p>{{ $data['description'] }}</p>
                        @endif
                    </section>
                @endif

                @if ($type === 'text')
                    <section id="{{ $sectionId }}" class="article-section">
                        @if ($title)
                            <h2>{{ $title }}</h2>
                        @endif

                        @if (!empty($data['content']))
                            <div class="{{ $title ? '' : 'article-no-title' }}">
                                {!! $data['content'] !!}
                            </div>
                        @endif
                    </section>
                @endif

                @if ($type === 'warning')
                    @php
                        $noteClass = match ($data['type'] ?? 'warning') {
                            'info' => 'article-note-info',
                            'success' => 'article-note-success',
                            'danger' => 'article-note-danger',
                            default => 'article-note-warning',
                        };

                        $noteTitle = trim($data['title'] ?? '');
                    @endphp

                    <aside class="article-note {{ $noteClass }}">
                        @if ($noteTitle)
                            <strong>{{ $noteTitle }}</strong>
                        @endif

                        @if (!empty($data['content']))
                            <p class="{{ $noteTitle ? '' : 'article-note-no-title' }}">
                                {{ $data['content'] }}
                            </p>
                        @endif
                    </aside>
                @endif

                @if ($type === 'steps')
                    <section id="{{ $sectionId }}" class="article-section">
                        @if ($title)
                            <h2>{{ $title }}</h2>
                        @endif

                        <div class="{{ $title ? 'article-steps' : 'article-steps article-no-title' }}">
                            @foreach (($data['items'] ?? []) as $stepIndex => $item)
                                @php
                                    $stepTitle = trim($item['title'] ?? '');
                                @endphp

                                <div class="article-step">
                                    @if ($stepTitle)
                                        <h3>Шаг {{ $stepIndex + 1 }}. {{ $stepTitle }}</h3>
                                    @endif

                                    @if (!empty($item['text']))
                                        <p class="{{ $stepTitle ? '' : 'article-step-no-title' }}">
                                            {{ $item['text'] }}
                                        </p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @if ($type === 'checklist')
                    <section id="{{ $sectionId }}" class="article-section">
                        @if ($title)
                            <h2>{{ $title }}</h2>
                        @endif

                        <ul class="{{ $title ? '' : 'article-no-title' }}">
                            @foreach (($data['items'] ?? []) as $item)
                                <li>{{ $item['text'] ?? '' }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($type === 'tips')
                    <aside id="{{ $sectionId }}" class="article-note article-note-info">
                        @if ($title)
                            <strong>{{ $title }}</strong>
                        @endif

                        <ul class="{{ $title ? '' : 'article-note-no-title' }}">
                            @foreach (($data['items'] ?? []) as $item)
                                <li>{{ $item['text'] ?? '' }}</li>
                            @endforeach
                        </ul>
                    </aside>
                @endif

                @if ($type === 'faq')
                    <section id="{{ $sectionId }}" class="article-section">
                        @if ($title)
                            <h2>{{ $title }}</h2>
                        @endif

                        <div class="{{ $title ? 'article-faq' : 'article-faq article-no-title' }}">
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
                            <button
                                type="button"
                                class="article-image-frame"
                                @click="imageOpen = true; imageSrc = '{{ $imageUrl }}'; imageAlt = '{{ e($data['caption'] ?? $instruction->title) }}'"
                            >
                                <img
                                    src="{{ $imageUrl }}"
                                    alt="{{ $data['caption'] ?? $instruction->title }}"
                                >

                                <span class="article-image-open">↗</span>
                            </button>

                            @if (!empty($data['caption']))
                                <figcaption>
                                    {{ $data['caption'] }}
                                </figcaption>
                            @endif
                        </figure>
                    @endif
                @endif

                @if ($type === 'video')
                    <section id="{{ $sectionId }}" class="article-section">
                        @if ($title)
                            <h2>{{ $title }}</h2>
                        @endif

                        @if (!empty($data['url']))
                            <a href="{{ $data['url'] }}" target="_blank" class="article-button">
                                Открыть видео →
                            </a>
                        @endif
                    </section>
                @endif

                @if ($type === 'links')
                    <section id="{{ $sectionId }}" class="article-section">
                        @if ($title)
                            <h2>{{ $title }}</h2>
                        @endif

                        <div class="{{ $title ? 'article-links' : 'article-links article-no-title' }}">
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
    </article>

    {{-- модалка картинки --}}
    <div
        x-show="imageOpen"
        x-transition.opacity
        x-cloak
        class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/80 p-4"
        @click.self="imageOpen = false"
        @keydown.escape.window="imageOpen = false"
    >
        <button
            type="button"
            class="absolute right-4 top-4 z-10 rounded-full bg-white px-4 py-2 text-[14px] font-semibold text-[#061126]"
            @click="imageOpen = false"
        >
            Закрыть
        </button>

        <img
            :src="imageSrc"
            :alt="imageAlt"
            class="max-h-[86vh] max-w-full rounded-[18px] bg-white object-contain"
        >
    </div>
</section>

<style>
    [x-cloak] {
        display: none !important;
    }

    html {
        scroll-behavior: smooth;
    }

    .article-title {
        color: #061126;
        font-size: 31px;
        line-height: 1.08;
        font-weight: 800;
        letter-spacing: -0.055em;
    }

    .article-lead {
        margin-top: 22px;
        color: #061126;
        font-size: 18px;
        line-height: 1.45;
        letter-spacing: -0.015em;
    }

    .article-cover {
        margin-top: 24px;
        overflow: hidden;
        border-radius: 21px;
        background: #eef3f7;
    }

    .article-cover-button {
        display: block;
        width: 100%;
        padding: 0;
        border: 0;
        background: transparent;
        cursor: pointer;
    }

    .article-cover img {
        display: block;
        width: 100%;
        height: auto;
        max-height: 480px;
        object-fit: cover;
    }

    .article-toc-mobile {
        margin-top: 48px;
        padding: 20px 18px 22px;
        border-radius: 18px;
        background: #f1f1f1;
        color: #061126;
    }

    .article-toc-title {
        margin-bottom: 16px;
        color: #061126;
        font-size: 21px;
        line-height: 1.15;
        font-weight: 800;
        letter-spacing: -0.035em;
    }

    .article-toc-mobile nav {
        display: grid;
        gap: 14px;
    }

    .article-toc-mobile a {
        color: #061126;
        font-size: 16px;
        line-height: 1.35;
        text-decoration: none;
    }

    .article-body {
        margin-top: 44px;
        color: #061126;
        font-size: 16px;
        line-height: 1.45;
        letter-spacing: -0.02em;
    }

    .article-section {
        margin-bottom: 34px;
        scroll-margin-top: 28px;
    }

    .article-section:has(.article-no-title) {
        margin-top: 0;
    }

    .article-body h2 {
        margin: 0 0 18px;
        color: #061126;
        font-size: 29px;
        line-height: 1.08;
        font-weight: 800;
        letter-spacing: -0.055em;
    }

    .article-body h3 {
        margin: 28px 0 10px;
        color: #061126;
        font-size: 22px;
        line-height: 1.15;
        font-weight: 800;
        letter-spacing: -0.04em;
    }

    .article-body p {
        margin: 0 0 18px;
        color: #061126;
    }

    .article-no-title,
    .article-step-no-title,
    .article-note-no-title {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }

    .article-body ul,
    .article-body ol {
        margin: 16px 0 24px;
        padding-left: 24px;
        color: #061126;
    }

    .article-body li {
        margin: 8px 0;
    }

    .article-body a {
        color: #2f80ff;
        text-decoration: none;
    }

    .article-intro p {
        font-size: 18px;
        line-height: 1.45;
    }

    .article-note {
        margin: 34px 0 42px;
        padding: 22px 24px;
        border-radius: 22px;
        font-size: 18px;
        line-height: 1.45;
    }

    .article-note strong {
        display: block;
        margin-bottom: 8px;
        font-size: 18px;
        line-height: 1.35;
        font-weight: 700;
    }

    .article-note p {
        margin: 0;
        color: inherit;
    }

    .article-note ul {
        margin: 0;
        padding-left: 20px;
    }

    .article-note-warning {
        background: #fff3bf;
        color: #061126;
    }

    .article-note-info {
        background: #c9efff;
        color: #061126;
    }

    .article-note-success {
        background: #eaf8ef;
        color: #061126;
    }

    .article-note-danger {
        background: #fff0f0;
        color: #061126;
    }

    .article-steps {
        margin-top: 0;
    }

    .article-step {
        margin-bottom: 28px;
    }

    .article-step h3:first-child {
        margin-top: 0;
    }

    .article-step p:last-child,
    .article-section p:last-child,
    .article-note p:last-child {
        margin-bottom: 0;
    }

    .article-image-block {
        margin: 34px 0 38px;
    }

    .article-image-frame {
        position: relative;
        display: flex;
        width: 100%;
        min-height: 190px;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        padding: 18px;
        border: 0;
        border-radius: 24px;
        background: #d9d9d9;
        cursor: pointer;
    }

    .article-image-frame img {
        display: block;
        width: auto;
        max-width: 100%;
        max-height: 620px;
        height: auto;
        border-radius: 12px;
    }

    .article-image-open {
        position: absolute;
        left: 18px;
        bottom: 18px;
        display: flex;
        width: 32px;
        height: 32px;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        background: rgba(17, 24, 39, 0.28);
        color: white;
        font-size: 18px;
    }

    .article-image-block figcaption {
        margin-top: 12px;
        color: #061126;
        font-size: 15px;
        line-height: 1.4;
    }

    .article-faq {
        overflow: hidden;
        border-radius: 18px;
        background: #f1f1f1;
    }

    .article-faq details {
        padding: 18px 20px;
        border-bottom: 1px solid rgba(6, 17, 38, 0.08);
    }

    .article-faq details:last-child {
        border-bottom: 0;
    }

    .article-faq summary {
        cursor: pointer;
        list-style: none;
        color: #061126;
        font-size: 17px;
        font-weight: 700;
    }

    .article-faq summary::-webkit-details-marker {
        display: none;
    }

    .article-faq p {
        margin: 12px 0 0;
        font-size: 16px;
    }

    .article-links {
        display: grid;
        gap: 10px;
    }

    .article-links a {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 17px 18px;
        border-radius: 16px;
        background: #f1f1f1;
        color: #061126;
        font-size: 16px;
        font-weight: 600;
    }

    .article-button {
        display: inline-flex;
        margin-top: 6px;
        padding: 13px 18px;
        border-radius: 999px;
        background: #061126;
        color: white !important;
        font-size: 15px;
        font-weight: 700;
    }
</style>