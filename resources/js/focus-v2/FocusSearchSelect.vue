<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    modelValue: { type: [String, Number, null], default: null },
    label: { type: String, required: true },
    placeholder: { type: String, default: 'Поиск…' },
    disabled: { type: Boolean, default: false },
    id: { type: String, required: true },
});
const emit = defineEmits(['select']);
const root = ref(null);
const query = ref('');
const open = ref(false);
const activeIndex = ref(0);
const selected = computed(() => props.items.find(item => String(item.id) === String(props.modelValue)));
const filtered = computed(() => {
    const term = query.value.trim().toLocaleLowerCase('ru');
    return props.items.filter(item => !term || item.name.toLocaleLowerCase('ru').includes(term)).slice(0, 50);
});
const listId = computed(() => props.id + '-options');
watch([selected, open], () => {
    if (!open.value) query.value = selected.value?.name ?? '';
}, { immediate: true });
function startSearch() {
    if (props.disabled) return;
    if (!open.value) query.value = '';
    open.value = true;
    activeIndex.value = 0;
}
function choose(item) {
    emit('select', item.id);
    query.value = item.name;
    open.value = false;
}
function keydown(event) {
    if (!open.value && ['ArrowDown', 'ArrowUp'].includes(event.key)) startSearch();
    if (!open.value) return;
    if (event.key === 'Escape') {
        open.value = false;
        query.value = selected.value?.name ?? '';
    } else if (event.key === 'ArrowDown') {
        activeIndex.value = Math.min(filtered.value.length - 1, activeIndex.value + 1);
        event.preventDefault();
    } else if (event.key === 'ArrowUp') {
        activeIndex.value = Math.max(0, activeIndex.value - 1);
        event.preventDefault();
    } else if (event.key === 'Enter' && filtered.value[activeIndex.value]) {
        choose(filtered.value[activeIndex.value]);
        event.preventDefault();
    }
}
function clickAway(event) {
    if (root.value && !root.value.contains(event.target)) {
        open.value = false;
        query.value = selected.value?.name ?? '';
    }
}
onMounted(() => document.addEventListener('pointerdown', clickAway));
onBeforeUnmount(() => document.removeEventListener('pointerdown', clickAway));
</script>

<template>
    <div ref="root" class="relative">
        <label :for="id" class="block text-sm font-semibold text-slate-900">{{ label }}</label>
        <div class="mt-1.5 flex min-h-12 items-center gap-3 rounded-xl border border-slate-300 bg-white px-3 focus-within:border-blue-600 focus-within:ring-2 focus-within:ring-blue-100">
            <img v-if="selected?.thumbnail" :src="selected.thumbnail" alt="" class="size-9 shrink-0 rounded-lg object-cover">
            <span v-else-if="selected" class="grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-xs font-semibold text-slate-500">{{ selected.name.slice(0, 1) }}</span>
            <svg v-else class="size-5 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg>
            <input :id="id" v-model="query" type="search" autocomplete="off" :disabled="disabled" :placeholder="placeholder"
                role="combobox" aria-autocomplete="list" :aria-expanded="open" :aria-controls="listId"
                class="min-w-0 flex-1 border-0 bg-transparent py-3 text-base outline-none focus:ring-0 disabled:opacity-50"
                @focus="startSearch" @input="open = true; activeIndex = 0" @keydown="keydown">
            <button v-if="selected && !disabled" type="button" class="shrink-0 rounded-lg px-2 py-2 text-xs font-medium text-slate-500"
                :aria-label="'Изменить: ' + label" @click="startSearch">Изменить</button>
        </div>
        <div v-if="open && !disabled" :id="listId" role="listbox"
            class="absolute inset-x-0 top-full z-30 mt-1 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl">
            <button v-for="(item, index) in filtered" :key="item.id" type="button" role="option"
                :aria-selected="String(item.id) === String(modelValue)"
                class="flex min-h-12 w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm"
                :class="activeIndex === index ? 'bg-blue-50 text-blue-900' : 'hover:bg-slate-50'"
                @pointermove="activeIndex = index" @click="choose(item)">
                <img v-if="item.thumbnail" :src="item.thumbnail" alt="" class="size-10 shrink-0 rounded-lg object-cover">
                <span v-else class="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 font-semibold text-slate-600">{{ item.name.slice(0, 1) }}</span>
                <span class="min-w-0 flex-1 break-words font-medium">{{ item.name }}</span>
                <span v-if="String(item.id) === String(modelValue)" class="text-blue-700" aria-hidden="true">✓</span>
            </button>
            <p v-if="filtered.length === 0" class="p-4 text-sm text-slate-500">Ничего не найдено</p>
        </div>
    </div>
</template>
