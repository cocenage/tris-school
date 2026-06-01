<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

    <title>{{ $title ?? config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="bg-[#FAFAFA] text-[#111]">
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

    <sc
ript src="https://telegram.org/js/telegram-web-app.js"></sc>
    @livewireScripts
</body>
</html>