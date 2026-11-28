@props(['kind', 'requestId', 'attachments' => []])
<div class="space-y-2" x-data="controlMediaGallery" data-media-group="attachments" data-media-title="Вложения">
    <div class="text-xs font-medium text-[#777777]">Вложения</div>
    <div class="grid grid-cols-2 gap-2">
        @foreach($attachments as $index => $attachment)
            @if(is_array($attachment) && filled($attachment['path'] ?? null) && is_numeric($index))
                @php
                    $url = route('staff-request.attachment', ['kind' => $kind, 'requestId' => $requestId, 'index' => $index]);
                    $mime = $attachment['mime'] ?? '';
                    $name = $attachment['name'] ?? 'Вложение';
                    $isImage = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true);
                    $isVideo = in_array($mime, ['video/mp4', 'video/webm', 'video/quicktime', 'video/ogg'], true);
                @endphp
                @if($isImage)
                    <div class="min-w-0" x-data="{ failed: false, visible: false }" x-effect="if (expanded) visible = true">
                        <button type="button" data-control-media data-kind="image" data-url="{{ $url }}" data-caption="{{ $name }}" x-on:click="open($el)" class="tris-mobile-press relative block w-full overflow-hidden rounded-[14px] bg-[#F5F5F5]" aria-label="Открыть {{ $name }}">
                            <img x-bind:src="visible ? @js($url) : null" x-show="!failed" x-on:error="failed = true"
                                alt="{{ $name }}" loading="lazy" decoding="async" class="aspect-square w-full object-cover" />
                            <div x-cloak x-show="failed" class="flex aspect-square flex-col items-center justify-center gap-1 px-2 text-center text-xs text-[#777777]">
                                <x-heroicon-o-photo class="size-6" /> Не удалось загрузить фото
                            </div>
                        </button>
                    </div>
                @elseif($isVideo)
                    <button type="button" data-control-media data-kind="video" data-url="{{ $url }}" data-caption="{{ $name }}" x-on:click="open($el)"
                        class="tris-mobile-press flex aspect-square min-w-0 flex-col items-center justify-center gap-2 rounded-[14px] bg-[#F5F5F5] p-3 text-[#777777]" aria-label="Смотреть {{ $name }}">
                        <x-heroicon-o-play-circle class="size-9" />
                        <span class="w-full truncate text-center text-xs">{{ $name }}</span>
                    </button>
                @else
                    <a href="{{ $url }}?download=1" class="tris-mobile-press col-span-2 flex min-w-0 items-center gap-3 rounded-[14px] bg-[#F5F5F5] p-3">
                        <x-heroicon-o-document class="size-6 shrink-0 text-[#777777]" />
                        <span class="min-w-0 flex-1 truncate text-sm">{{ $name }}</span>
                        <x-heroicon-o-arrow-down-tray class="size-4 shrink-0 text-[#777777]" />
                    </a>
                @endif
            @endif
        @endforeach
    </div>
    <x-mobile.media-viewer label="Вложения заявки" />
</div>
