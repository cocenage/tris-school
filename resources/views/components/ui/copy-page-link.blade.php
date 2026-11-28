@props(['compact' => false])
<button type="button" data-tris-copy-page
    aria-label="Скопировать ссылку на страницу" title="Скопировать ссылку на страницу"
    {{ $attributes->class(['inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-xl px-2 text-sm font-medium', 'hover:bg-gray-100 dark:hover:bg-gray-800']) }}>
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 shrink-0">
        <path d="M10 13a4 4 0 0 0 6 .4l3-3a4 4 0 0 0-5.7-5.7l-1.7 1.7M14 11a4 4 0 0 0-6-.4l-3 3a4 4 0 0 0 5.7 5.7l1.7-1.7"/>
    </svg>
    @unless($compact)<span>Скопировать ссылку</span>@endunless
</button>
