@props(['title' => 'TRIS Knowledge'])
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · TRIS Knowledge</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="knowledge-shell">
    <a class="knowledge-skip" href="#knowledge-content">К содержанию</a>
    <header class="knowledge-topbar">
        <a class="knowledge-brand" href="{{ route('knowledge.roadmap') }}">TRIS <span>Knowledge</span></a>
        <nav aria-label="Навигация библиотеки">
            <a href="{{ route('knowledge.roadmap') }}" @if(request()->routeIs('knowledge.roadmap')) aria-current="page" @endif>Roadmap</a>
            <a href="{{ route('knowledge.entities') }}" @if(request()->routeIs('knowledge.entities')) aria-current="page" @endif>Все статьи</a>
            <a href="{{ route('page-home.instructions') }}">Инструкции</a>
            <a href="{{ route('page-home') }}">Академия ↗</a>
        </nav>
    </header>
    <main id="knowledge-content" class="knowledge-main">{{ $slot }}</main>
</body>
</html>
