<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>Focus Control V2 · TRIS Academy</title>
    @vite(['resources/css/app.css', 'resources/js/focus-v2/main.js'])
</head>
<body class="bg-[#EDF0F4]">
    <div
        id="focus-v2-app"
        data-bootstrap-url="{{ route('page-checks.control.focus-v2.bootstrap') }}"
        data-draft-url="{{ route('page-checks.control.focus-v2.draft') }}"
        data-submit-url="{{ route('page-checks.control.focus-v2.submit') }}"
        data-review-status-url="{{ isset($reviewResponse) ? route('page-checks.control.focus-v2.result-media-status', $reviewResponse) : '' }}"
        data-media-start-url="{{ route('page-checks.control.focus-v2.media.start') }}"
        data-classic-url="{{ route('page-checks.control.focus') }}"
    >
        <div class="mx-auto max-w-2xl p-6 text-center text-sm text-slate-600">
            Загрузка Focus Control…
        </div>
    </div>
</body>
</html>
