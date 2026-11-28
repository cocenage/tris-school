@props(['options', 'selected', 'model', 'label' => 'Разделы'])
<div role="group" aria-label="{{ $label }}" {{ $attributes->class(['flex flex-wrap gap-[5px]']) }}>
    @foreach($options as $value => $text)
        <button type="button" wire:click="$set('{{ $model }}', '{{ $value }}')" aria-pressed="{{ $selected === $value ? 'true' : 'false' }}"
            @class(['tris-mobile-press inline-flex h-9 items-center rounded-full px-[14px] text-[14px] font-medium leading-none', 'bg-[#2A1F1A] text-white' => $selected === $value, 'bg-[#EAEAEA] text-[#666666]' => $selected !== $value])>{{ $text }}</button>
    @endforeach
</div>
