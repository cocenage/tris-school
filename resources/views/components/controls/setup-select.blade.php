@props(['label', 'options', 'model', 'placeholder', 'icon', 'field', 'freeText' => false])

<div class="min-w-0 text-sm" x-data="{
    options: @js(array_values($options)),
    freeText: @js($freeText),
    open: false,
    query: '',
    get filtered() {
        const search = this.query.trim().toLocaleLowerCase();
        return this.options.filter(option => option.name.toLocaleLowerCase().includes(search));
    },
    findSelection(value) {
        return this.options.find(option => String(this.freeText ? option.name : option.id) === String(value));
    },
    close() { this.open = false; },
}" x-id="['setup-label', 'setup-search']" @click.outside="close()"
    @keydown.escape.stop="if (open) { close(); $refs.trigger.focus(); }"
    @focusout="if (!$el.contains($event.relatedTarget)) close()">
    <div :id="$id('setup-label')" class="font-semibold">{{ $label }}
        @if($freeText)
            <span class="font-normal text-[#596476]">· необязательно</span>
        @else
            <span aria-hidden="true" class="text-[#285B86]">*</span>
        @endif
    </div>
    <button type="button" x-ref="trigger" :aria-expanded="open" :aria-describedby="$id('setup-label')" @error($field) aria-invalid="true" @enderror
        @click="if (open) { close(); } else { query = freeText ? ({{ $model }} || '') : ''; open = true; $nextTick(() => $refs.search.focus()); }"
        class="mt-1.5 flex min-h-12 w-full min-w-0 items-center gap-2 rounded-2xl border border-[#D7DEE6] bg-white px-3 py-2 text-left text-base focus-visible:ring-2 focus-visible:ring-[#285B86]">
        <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center overflow-hidden rounded-full bg-[#EAEBED] text-[#526273]">
            <template x-if="findSelection({{ $model }})?.thumbnail">
                <img :src="findSelection({{ $model }}).thumbnail" alt="" class="size-full object-cover">
            </template>
            <span x-show="!findSelection({{ $model }})?.thumbnail"><x-dynamic-component :component="$icon" class="size-[18px]" /></span>
        </span>
        <span class="min-w-0 flex-1 truncate" x-text="findSelection({{ $model }})?.name || (freeText && {{ $model }}) || @js($placeholder)"></span>
        <x-heroicon-o-chevron-down aria-hidden="true" class="size-4 shrink-0 text-[#596476]" />
    </button>
    <div x-show="open" x-cloak class="mt-1.5 min-w-0 rounded-2xl border border-[#D7DEE6] bg-white p-2">
        <label :for="$id('setup-search')" class="sr-only">Поиск: {{ $label }}</label>
        <input x-ref="search" :id="$id('setup-search')" type="search" x-model="query" maxlength="255" autocomplete="off"
            placeholder="Поиск" @error($field) aria-invalid="true" @enderror
            class="min-h-11 w-full min-w-0 rounded-xl border-[#D7DEE6] text-base">
        <div class="mt-1 max-h-56 overflow-y-auto overscroll-contain">
            <template x-for="option in filtered" :key="option.id">
                <button type="button" :aria-pressed="String({{ $model }}) === String(freeText ? option.name : option.id)"
                    @click="{{ $model }} = freeText ? option.name : option.id; scheduleAutosave(); close(); $refs.trigger.focus();"
                    class="flex min-h-11 w-full min-w-0 items-center gap-2 rounded-xl px-2 py-1 text-left hover:bg-[#EDF4FB] focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[#285B86]">
                    <span aria-hidden="true" class="grid size-8 shrink-0 place-items-center overflow-hidden rounded-full bg-[#EAEBED] text-[#526273]">
                        <template x-if="option.thumbnail"><img :src="option.thumbnail" alt="" loading="lazy" class="size-full object-cover"></template>
                        <span x-show="!option.thumbnail"><x-dynamic-component :component="$icon" class="size-[18px]" /></span>
                    </span>
                    <span class="min-w-0 break-words" x-text="option.name"></span>
                </button>
            </template>
            <p x-show="filtered.length === 0" class="px-2 py-2 text-[#596476]">Ничего не найдено</p>
        </div>
        @if($freeText)
            <button type="button" x-show="query.trim() || {{ $model }}"
                @click="{{ $model }} = query.trim(); scheduleAutosave(); close(); $refs.trigger.focus();"
                class="min-h-11 px-2 text-left font-semibold text-[#285B86]" x-text="query.trim() ? 'Использовать введённое имя' : 'Очистить поле'"></button>
        @endif
    </div>
    @error($field) <span class="mt-1.5 block text-xs text-[#B42318]">{{ $message }}</span> @enderror
</div>
