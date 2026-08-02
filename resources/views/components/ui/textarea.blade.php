@props([
    'rows' => 4,
])

<textarea
    rows="{{ $rows }}"
    {{ $attributes->merge([
        'class' => 'w-full rounded-[23px] border border-[#E7E7E7] bg-[#F8F8F8] px-[20px] py-[15px] text-[16px] placeholder:text-black/35 outline-none transition focus:border-[#D6D6D6] focus:bg-white focus:ring-0',
    ]) }}
>{{ $slot }}</textarea>
