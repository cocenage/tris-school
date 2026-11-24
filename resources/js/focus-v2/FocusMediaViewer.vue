<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    items: { type: Array, required: true },
    initialIndex: { type: Number, default: 0 },
    canRemove: { type: Boolean, default: false },
});
const emit = defineEmits(['close', 'remove']);
const index = ref(Math.max(0, props.initialIndex));
const container = ref(null);
const imageFailed = ref(false);
const item = computed(() => props.items[Math.min(index.value, Math.max(0, props.items.length - 1))] ?? null);
const count = computed(() => props.items.length);
const isVideo = computed(() => String(item.value?.mime || '').startsWith('video/'));
const imageUrl = ref('');
const fallback = ref('');
const itemKey = computed(() => item.value?.key ?? '');
const canDelete = computed(() => props.canRemove && (
    (item.value?.kind === 'saved' && Boolean(item.value?.source?.upload_id))
    || (item.value?.kind === 'upload' && ['queued', 'uploading', 'error'].includes(item.value?.status))
));

function setImage() {
    imageFailed.value = false;
    const source = item.value?.source ?? {};
    imageUrl.value = source.preview_url || source.url || source.thumbnail_url || item.value?.url || '';
    fallback.value = source.thumbnail_url || '';
}
watch(itemKey, setImage, { immediate: true });
function imageError() {
    if (fallback.value && imageUrl.value !== fallback.value) {
        imageUrl.value = fallback.value;
    } else {
        imageFailed.value = true;
    }
}
function move(direction) {
    if (count.value <= 1) return;
    index.value = (index.value + direction + count.value) % count.value;
}
function handleKey(event) {
    if (event.key === 'Escape') emit('close');
    if (event.key === 'ArrowRight') { event.preventDefault(); move(1); }
    if (event.key === 'ArrowLeft') { event.preventDefault(); move(-1); }
}
let touchStart = null;
function beginTouch(event) {
    if (isVideo.value || event.touches.length !== 1) return;
    touchStart = { x: event.touches[0].clientX, y: event.touches[0].clientY };
}
function endTouch(event) {
    if (!touchStart || isVideo.value || !event.changedTouches.length) return;
    const dx = event.changedTouches[0].clientX - touchStart.x;
    const dy = event.changedTouches[0].clientY - touchStart.y;
    touchStart = null;
    if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy) * 1.4) move(dx < 0 ? 1 : -1);
}
function deleteCurrent() {
    if (item.value && canDelete.value) emit('remove', item.value);
}
onMounted(() => { void nextTick(() => container.value?.focus()); });
</script>

<template>
    <div ref="container" class="fixed inset-0 z-50 flex flex-col bg-black/95 text-white outline-none"
        tabindex="-1" role="dialog" aria-modal="true" aria-label="Галерея фото и видео"
        @keydown="handleKey" @touchstart.passive="beginTouch" @touchend.passive="endTouch">
        <header class="flex min-h-16 items-center justify-between gap-3 px-4 pb-2 pt-[max(1rem,env(safe-area-inset-top))]">
            <button type="button" class="grid size-11 place-items-center rounded-full bg-white/15 text-xl"
                aria-label="Закрыть галерею" @click="emit('close')">✕</button>
            <div class="min-w-0 text-center">
                <p class="text-sm font-semibold" aria-live="polite">{{ Math.min(index + 1, count) }} из {{ count }}</p>
                <p class="max-w-52 truncate text-xs text-white/65">{{ item?.name || 'Медиа' }}</p>
            </div>
            <button v-if="canDelete" type="button" class="rounded-xl bg-red-500/20 px-3 py-3 text-xs font-bold text-red-100"
                aria-label="Удалить этот файл" @click="deleteCurrent">Удалить</button>
            <div v-else class="size-11"></div>
        </header>

        <div class="relative flex min-h-0 flex-1 items-center justify-center px-3" @click.self="emit('close')">
            <template v-if="item">
                <video v-if="isVideo" :key="itemKey" :src="item.source?.preview_url || item.source?.url || item.url"
                    controls playsinline preload="metadata" class="max-h-full w-full max-w-3xl object-contain"
                    :aria-label="item.name || 'Видео'"></video>
                <img v-else-if="!imageFailed" :key="itemKey + imageUrl" :src="imageUrl"
                    :alt="item.name || 'Фото'" class="max-h-full max-w-full select-none object-contain"
                    @error="imageError">
                <p v-else class="max-w-sm rounded-xl bg-white/15 p-5 text-center text-sm">
                    Просмотр этого изображения не поддерживается. Исходный файл остаётся в контрольной проверке.
                </p>
            </template>
            <button v-if="count > 1" type="button"
                class="absolute left-2 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-black/55 text-2xl"
                aria-label="Предыдущее вложение" @click="move(-1)">‹</button>
            <button v-if="count > 1" type="button"
                class="absolute right-2 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-full bg-black/55 text-2xl"
                aria-label="Следующее вложение" @click="move(1)">›</button>
        </div>
        <footer class="px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3 text-center text-xs text-white/65">
            <p v-if="item?.kind === 'saved'">✓ Сохранено на сервере</p>
            <p v-else-if="item?.status === 'error'">Ошибка передачи. Вернитесь к вопросу, чтобы повторить.</p>
            <p v-else>Загрузка ещё не завершена — не закрывайте Telegram</p>
            <p v-if="count > 1" class="mt-1">Листайте свайпом или стрелками</p>
        </footer>
    </div>
</template>
