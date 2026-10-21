<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

        <style>
            #loader-preview-overlay {
                position: fixed;
                inset: 0;
                z-index: 2147483647;
                display: grid;
                place-items: center;
                padding: max(0px, env(safe-area-inset-top)) max(0px, env(safe-area-inset-right)) max(0px, env(safe-area-inset-bottom)) max(0px, env(safe-area-inset-left));
                background: transparent;
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 250ms ease, visibility 0s linear 250ms;
            }

            #loader-preview-overlay.is-active {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
                transition: opacity 250ms ease;
            }

            #loader-preview-overlay.is-dismissing {
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 250ms ease, visibility 0s linear 250ms;
            }

            #loader-preview-overlay img {
                display: block;
                width: min(128px, 40vw);
                height: auto;
            }

            @media (prefers-reduced-motion: reduce) {
                #loader-preview-overlay,
                #loader-preview-overlay.is-active,
                #loader-preview-overlay.is-dismissing {
                    transition: none;
                }
            }
        </style>
</head>

<body class="bg-[#FAFAFA] text-[#111]">
        <div id="loader-preview-overlay" aria-hidden="true">
            <img src="{{ asset('assets/loaders/tris_academy_canvas_preview.gif') }}" alt="" width="128" height="128" fetchpriority="high">
        </div>

    <div class="mx-auto flex h-[100dvh] w-full max-w-[768px] flex-col overflow-hidden bg-[#FAFAFA]">

        <header class="shrink-0">
            @includeIf('components.partials.⚡header', [
                'header' => $header ?? null,
            ])
        </header>

        <main class="min-h-0 flex-1 overflow-y-auto rounded-[50px] bg-white">
            {{ $slot }}
                </main>

   @auth
    <livewire:access.required />
@endauth

        <x-ui.toast />

        <footer class="shrink-0">
        @includeIf('components.partials.⚡navbar')
        </footer>
        </div>

<script src="https://telegram.org/js/telegram-web-app.js"></script>
    @livewireScripts

        <script>
            (() => {
                const overlay = document.getElementById('loader-preview-overlay');
                const image = overlay?.querySelector('img');

                if (!overlay || !image || window.__trisLoaderStarted) {
                    overlay?.remove();
                    return;
                }

                window.__trisLoaderStarted = true;

                let dismissed = false;
                let revealTimer;
                let safetyTimer;

                const dismiss = () => {
                    if (dismissed) {
                        return;
                    }

                    dismissed = true;
                    window.__trisLoaderCompleted = true;
                    window.clearTimeout(safetyTimer);
                    window.clearTimeout(revealTimer);
                    overlay.classList.remove('is-active');
                    overlay.classList.add('is-dismissing');
                    overlay.setAttribute('aria-hidden', 'true');
                    window.setTimeout(() => overlay.remove(), 250);
                };

                safetyTimer = window.setTimeout(dismiss, 8000);

                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    dismiss();
                    return;
                }

                image.addEventListener('error', dismiss, { once: true });
                const reveal = () => {
                    if (!dismissed && image.complete && image.naturalWidth > 0 && document.readyState !== 'complete') {
                        overlay.classList.add('is-active');
                    }
                };
                image.addEventListener('load', reveal, { once: true });
                revealTimer = window.setTimeout(reveal, 150);
                window.addEventListener('load', dismiss, { once: true });
                document.addEventListener('livewire:navigating', dismiss, { once: true });
                document.addEventListener('livewire:navigated', () => {
                    if (window.__trisLoaderCompleted) document.getElementById('loader-preview-overlay')?.remove();
                });
                if (document.readyState === 'complete' || (image.complete && image.naturalWidth === 0)) dismiss();
            })();
        </script>
</body>
</html>
