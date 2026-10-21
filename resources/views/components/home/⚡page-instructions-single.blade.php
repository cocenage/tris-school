<?php

use App\Models\Instruction;
use Illuminate\Support\Facades\Storage;
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
                    'id' => 'section-'.$index,
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
<x-ui.protected-content>
<section
    x-data="{ imageOpen: false, imageSrc: '', imageAlt: '' }"
    class="instruction-reader bg-white px-4 py-5 sm:px-5"
>
    <article class="mx-auto w-full max-w-[768px] pb-8">
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
                            <div class="article-content {{ $title ? '' : 'article-no-title' }}">
                                {!! $data['content'] !!}
                            </div>
                        @endif
                    </section>
                @endif

                @if ($type === 'warning')
                    <x-ui.instruction-callout
                        :type="$data['type'] ?? 'warning'"
                        :title="$data['title'] ?? ''"
                        :content="$data['content'] ?? ''"
                        :id="$sectionId"
                    />
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
            class="absolute right-4 top-4 z-10 rounded-full bg-white px-4 py-2 text-[14px]  text-[#061126]"
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
</x-ui.protected-content>
<style>
    [x-cloak] {
        display: none !important;
    }

    html {
        scroll-behavior: smooth;
    }

    .article-title {
        color: #111827;
        font-size: 26px;
        line-height: 1.25;
        font-weight: 600;
        letter-spacing: -0.025em;
        overflow-wrap: anywhere;
    }

    .article-lead {
        margin-top: 12px;
        color: #4b5563;
        font-size: 16px;
        line-height: 1.6;
        letter-spacing: 0;
        overflow-wrap: anywhere;
    }

    .article-cover {
        margin-top: 22px;
        overflow: hidden;
        border-radius: 10px;
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
        object-fit: contain;
    }

    .article-toc-mobile {
        margin-top: 28px;
        color: #374151;
    }

    .article-toc-title {
        margin-bottom: 10px;
        color: #111827;
        font-size: 16px;
        line-height: 1.4;
        font-weight: 600;
    }

    .article-toc-mobile nav {
        display: grid;
        gap: 8px;
    }

    .article-toc-mobile a {
        color: #374151;
        font-size: 15px;
        line-height: 1.5;
        text-decoration: underline;
        text-decoration-color: #d1d5db;
        text-underline-offset: 3px;
        overflow-wrap: anywhere;
    }

    .article-body,
    .article-content {
        color: #1f2937;
        font-size: 16px;
        line-height: 1.6;
        letter-spacing: 0;
        overflow-wrap: anywhere;
        word-break: normal;
    }

    .article-section {
        margin-bottom: 30px;
        scroll-margin-top: 20px;
    }

    .article-section:has(.article-no-title) {
        margin-top: 0;
    }

    .article-body h1,
    .article-content h1 {
        margin: 24px 0 12px;
        color: #111827;
        font-size: 26px;
        line-height: 1.3;
        font-weight: 600;
        letter-spacing: -0.02em;
        overflow-wrap: anywhere;
    }

    .article-body h2,
    .article-content h2 {
        margin: 0 0 12px;
        color: #111827;
        font-size: 21px;
        line-height: 1.35;
        font-weight: 600;
        letter-spacing: -0.015em;
        overflow-wrap: anywhere;
    }

    .article-body h3,
    .article-content h3 {
        margin: 22px 0 8px;
        color: #111827;
        font-size: 18px;
        line-height: 1.4;
        font-weight: 600;
        letter-spacing: -0.01em;
        overflow-wrap: anywhere;
    }

    .article-body h4,
    .article-content h4 {
        margin: 18px 0 8px;
        color: #111827;
        font-size: 16px;
        line-height: 1.5;
        font-weight: 600;
    }

    .article-body p,
    .article-content p {
        margin: 0 0 14px;
        color: inherit;
    }

    .article-no-title,
    .article-step-no-title,
    .article-note-no-title {
        margin-top: 0 !important;
        padding-top: 0 !important;
    }

    .article-body ul,
    .article-body ol,
    .article-content ul,
    .article-content ol {
        margin: 12px 0 18px;
        padding-left: 25px;
        color: inherit;
    }

    .article-body li,
    .article-content li {
        margin: 6px 0;
    }

    .article-body a,
    .article-content a {
        color: #2563eb;
        text-decoration: underline;
        text-decoration-thickness: 1px;
        text-underline-offset: 3px;
        overflow-wrap: anywhere;
    }

    .article-intro p {
        font-size: 16px;
        line-height: 1.6;
    }

    .article-note {
        margin: 24px 0 30px;
        padding: 2px 0 2px 15px;
        border-left: 2px solid #9ca3af;
        font-size: 16px;
        line-height: 1.6;
        color: #374151;
    }

    .article-note strong {
        display: block;
        margin-bottom: 4px;
        color: #111827;
        font-size: 16px;
        line-height: 1.5;
        font-weight: 600;
    }

    .article-note p {
        margin: 0;
        color: inherit;
    }

    .article-note ul {
        margin: 0;
        padding-left: 20px;
    }

    .article-steps {
        margin-top: 0;
    }

    .article-step {
        margin-bottom: 22px;
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
        margin: 24px 0 28px;
    }

    .article-image-frame {
        position: relative;
        display: flex;
        width: 100%;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        padding: 0;
        border: 0;
        border-radius: 8px;
        background: transparent;
        cursor: pointer;
    }

    .article-image-frame img {
        display: block;
        width: 100%;
        max-width: 100%;
        max-height: 620px;
        height: auto;
        object-fit: contain;
        border-radius: 8px;
    }

    .article-image-open {
        position: absolute;
        left: 8px;
        bottom: 8px;
        display: flex;
        width: 32px;
        height: 32px;
        align-items: center;
        justify-content: center;
        border-radius: 5px;
        background: rgba(17, 24, 39, 0.28);
        color: white;
        font-size: 18px;
    }

    .article-image-block figcaption {
        margin-top: 8px;
        color: #6b7280;
        font-size: 14px;
        line-height: 1.5;
    }

    .article-faq {
        border-top: 1px solid #e5e7eb;
    }

    .article-faq details {
        padding: 13px 0;
        border-bottom: 1px solid #e5e7eb;
    }

    .article-faq details:last-child {
        border-bottom: 0;
    }

    .article-faq summary {
        cursor: pointer;
        list-style: none;
        color: #111827;
        font-size: 16px;
        line-height: 1.5;
        font-weight: 600;
        overflow-wrap: anywhere;
    }

    .article-faq summary::-webkit-details-marker {
        display: none;
    }

    .article-faq p {
        margin: 10px 0 0;
        font-size: 16px;
        line-height: 1.6;
    }

    .article-links {
        display: grid;
        gap: 8px;
    }

    .article-links a {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 6px 0;
        color: #2563eb;
        font-size: 16px;
        line-height: 1.5;
        font-weight: 400;
        text-decoration: underline;
        text-underline-offset: 3px;
        overflow-wrap: anywhere;
    }

    .article-button {
        display: inline-flex;
        margin-top: 4px;
        color: #2563eb !important;
        font-size: 16px;
        line-height: 1.5;
        text-decoration: underline;
        text-underline-offset: 3px;
    }

    .article-body blockquote,
    .article-content blockquote {
        margin: 20px 0;
        padding-left: 16px;
        border-left: 2px solid #d1d5db;
        color: #4b5563;
    }

    .article-body hr,
    .article-content hr {
        margin: 28px 0;
        border: 0;
        border-top: 1px solid #e5e7eb;
    }

    .article-body img,
    .article-body video,
    .article-body iframe,
    .article-content img,
    .article-content video,
    .article-content iframe {
        display: block;
        max-width: 100%;
        height: auto;
        margin-inline: auto;
    }

    .article-body table,
    .article-content table {
        display: block;
        max-width: 100%;
        overflow-x: auto;
        border-collapse: collapse;
    }

    @media (max-width: 380px) {
        .article-title {
            font-size: 25px;
        }

        .article-body,
        .article-content {
            font-size: 16px;
        }
    }
</style>
