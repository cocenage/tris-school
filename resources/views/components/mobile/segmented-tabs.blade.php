@props(['options', 'selected', 'model', 'label' => 'Разделы'])
<div role="group" aria-label="{{ $label }}" {{ $attributes->class(['flex flex-wrap gap-1.5']) }}>
    @foreach($options as $value => $text)
        <button type="button" wire:click="$set('{{ $model }}', '{{ $value }}')" aria-pressed="{{ $selected === $value ? 'true' : 'false' }}"
            @class(['rounded-full px-3.5 py-2 text-sm leading-5', 'bg-[#2A1F1A] text-white' => $selected === $value, 'bg-[#E7E7E7] text-[#606060]' => $selected !== $value])>{{ $text }}</button>
    @endforeach
</div>
