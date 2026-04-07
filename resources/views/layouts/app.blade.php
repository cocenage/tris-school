<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>{{ $title ?? config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-[#F2F3F7]">
    <div class="min-h-screen flex items-center justify-center">
        <div class="w-full max-w-[768px] h-[100dvh] bg-white overflow-hidden">
            <div class="h-full flex flex-col">
                <main class="flex-1 overflow-y-auto bg-[#F2F3F7]">
                    {{ $slot }}
                </main>

                <div class="shrink-0">
                    <x-navbar />
                </div>
            </div>
        </div>
    </div>

    @livewireScripts
</body>

</html>