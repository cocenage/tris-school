@props([
    'type' => 'warning',
    'title' => '',
    'content' => '',
])

@php
    $variant = match ($type) {
        'info' => [
            'background' => 'bg-[#EFF6FF] dark:bg-[#172554]',
            'border' => 'border-l-[#2563EB] dark:border-l-[#93C5FD]',
            'icon' => 'info',
            'iconColor' => 'text-[#2563EB] dark:text-[#93C5FD]',
        ],
        'danger' => [
            'background' => 'bg-[#FEF2F2] dark:bg-[#450A0A]',
            'border' => 'border-l-[#DC2626] dark:border-l-[#FCA5A5]',
            'icon' => 'alert-octagon',
            'iconColor' => 'text-[#DC2626] dark:text-[#FCA5A5]',
        ],
        'success' => [
            'background' => 'bg-[#F0FDF4] dark:bg-[#052E16]',
            'border' => 'border-l-[#16A34A] dark:border-l-[#86EFAC]',
            'icon' => 'check-circle',
            'iconColor' => 'text-[#16A34A] dark:text-[#86EFAC]',
        ],
        default => [
            'background' => 'bg-[#FFFBEB] dark:bg-[#422006]',
            'border' => 'border-l-[#D97706] dark:border-l-[#FCD34D]',
            'icon' => 'alert-triangle',
            'iconColor' => 'text-[#D97706] dark:text-[#FCD34D]',
        ],
    };

    $calloutTitle = trim($title);
@endphp

<aside
    {{ $attributes->class([
        'my-5 flex min-w-0 items-start gap-3 rounded-r-xl border-l-[3px] px-3 py-3 text-[15px] leading-6 text-[#1F2937] dark:text-slate-100 sm:px-4',
        $variant['background'],
        $variant['border'],
    ]) }}
    data-callout-type="{{ in_array($type, ['info', 'warning', 'danger', 'success'], true) ? $type : 'warning' }}"
>
    <svg
        class="mt-0.5 h-5 w-5 shrink-0 {{ $variant['iconColor'] }}"
        data-callout-icon="{{ $variant['icon'] }}"
        xmlns="http://www.w3.org/2000/svg"
        width="24"
        height="24"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
    >
        @if ($variant['icon'] === 'info')
            <circle cx="12" cy="12" r="10" />
            <path d="M12 16v-4M12 8h.01" />
        @elseif ($variant['icon'] === 'alert-triangle')
            <path d="m10.29 3.86-8.15 14.14A2 2 0 0 0 3.87 21h16.26a2 2 0 0 0 1.73-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
            <path d="M12 9v4M12 17h.01" />
        @elseif ($variant['icon'] === 'alert-octagon')
            <path d="M7.86 2h8.28L22 7.86v8.28L16.14 22H7.86L2 16.14V7.86L7.86 2Z" />
            <path d="M12 8v4M12 16h.01" />
        @else
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
            <path d="m9 11 3 3L22 4" />
        @endif
    </svg>

    <div class="min-w-0 flex-1 break-words">
        @if ($calloutTitle !== '')
            <strong class="mb-0.5 block font-semibold leading-5 text-[#111827] dark:text-white">
                {{ $calloutTitle }}
            </strong>
        @endif

        @if (trim($content) !== '')
            <p class="m-0 whitespace-pre-line">{{ $content }}</p>
        @endif
    </div>
</aside>
