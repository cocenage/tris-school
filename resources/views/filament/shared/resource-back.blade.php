@php
    $routeName = request()->route()?->getName() ?? '';
    $indexRoute = null;

    if (\Illuminate\Support\Str::is('filament.*.resources.*.*', $routeName)) {
        $candidate = \Illuminate\Support\Str::beforeLast($routeName, '.') . '.index';

        if ($candidate !== $routeName && \Illuminate\Support\Facades\Route::has($candidate)) {
            $indexRoute = $candidate;
        }
    }
@endphp

@if ($indexRoute)
    <a class="tris-resource-back" href="{{ route($indexRoute) }}">
        <x-filament::icon icon="heroicon-o-arrow-left" class="h-5 w-5" />
        <span>Назад к списку</span>
    </a>
@endif
