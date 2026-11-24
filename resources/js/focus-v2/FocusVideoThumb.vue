<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    src: { type: String, required: true },
    name: { type: String, default: 'Видео' },
});
const host = ref(null);
const video = ref(null);
const visible = ref(false);
const duration = ref(null);
const failed = ref(false);
let observer = null;
const durationLabel = computed(() => {
    if (duration.value == null) return '';
    const seconds = Math.floor(duration.value);
    return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
});
function loaded(event) {
    const element = event.target;
    duration.value = Number.isFinite(element.duration) ? element.duration : null;
    // Native poster images are often absent. Seek a small amount into the
    // clip to render the actual first meaningful frame on supported WebViews.
    try {
        if (element.seekable?.length && element.duration > 0.2) {
            element.currentTime = Math.min(0.1, element.duration / 2);
        }
    } catch (_) { /* Black fallback is safer than failing the upload UI. */ }
}
onMounted(() => {
    if (!('IntersectionObserver' in window)) {
        visible.value = true;
        return;
    }
    observer = new IntersectionObserver(entries => {
        if (entries.some(entry => entry.isIntersecting)) {
            visible.value = true;
            observer?.disconnect();
        }
    }, { rootMargin: '80px' });
    if (host.value) observer.observe(host.value);
});
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <div ref="host" class="absolute inset-0 overflow-hidden bg-slate-950" :aria-label="name">
        <video v-if="visible && !failed" ref="video" :src="src" preload="metadata"
            playsinline muted aria-hidden="true" class="size-full object-cover"
            @loadedmetadata="loaded" @error="failed = true"></video>
        <div v-if="failed || !visible" class="absolute inset-0 grid place-items-center text-3xl text-white/30" aria-hidden="true">▣</div>
        <span v-if="durationLabel" class="absolute right-1.5 top-1.5 rounded bg-black/70 px-1.5 py-0.5 text-[10px] font-bold text-white">
            {{ durationLabel }}
        </span>
    </div>
</template>
