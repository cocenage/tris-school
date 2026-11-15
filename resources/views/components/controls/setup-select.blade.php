@props(['label', 'options', 'model', 'placeholder', 'icon', 'field'])

<label class="block min-w-0 text-sm font-semibold">
    {{ $label }} <span aria-hidden="true" class="text-[#285B86]">*</span>
    <span class="relative mt-1.5 block" x-data="{ thumbnails: @js(array_column($options, 'thumbnail', 'id')) }">
        <span aria-hidden="true" class="pointer-events-none absolute left-2 top-1/2 z-[1] grid size-8 -translate-y-1/2 place-items-center overflow-hidden rounded-full bg-white/80 text-[#526273]">
            <template x-if="thumbnails[{{ $model }}]">
                <img :src="thumbnails[{{ $model }}]" alt="" class="size-full object-cover">
            </template>
            <span x-show="!thumbnails[{{ $model }}]" class="grid place-items-center">
                <x-dynamic-component :component="$icon" class="size-[18px]" />
            </span>
        </span>
        <select x-model="{{ $model }}" x-on:change="scheduleAutosave()" aria-required="true" @error($field) aria-invalid="true" @enderror
            class="min-h-12 w-full appearance-none truncate rounded-full border-0 bg-[#EAEBED] py-2 pl-12 pr-10 text-base font-normal text-[#20262D] focus:ring-2 focus:ring-[#285B86]">
            <option value="">{{ $placeholder }}</option>
            @foreach($options as $option)
                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
            @endforeach
        </select>
        <x-heroicon-o-chevron-down aria-hidden="true" class="pointer-events-none absolute right-4 top-1/2 size-4 -translate-y-1/2 text-[#596476]" />
    </span>
    @error($field) <span class="mt-1.5 block text-xs text-[#B42318]">{{ $message }}</span> @enderror
</label>
