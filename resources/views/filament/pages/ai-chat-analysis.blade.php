<x-filament-panels::page>

@if($prompt)

<x-filament::section>

<x-slot name="heading">
Промпт для ChatGPT
</x-slot>

<textarea
readonly
rows="25"
class="w-full rounded-xl border-gray-300 text-sm"
>{{ $prompt }}</textarea>

</x-filament::section>

@endif

</x-filament-panels::page>