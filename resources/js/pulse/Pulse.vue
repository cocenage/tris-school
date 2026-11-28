<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';

const props = defineProps({ endpoint: { type: String, required: true } });
const snapshot = ref(null);
const phase = ref('loading');
const changed = ref(new Set());
const clock = ref(new Date());
let timer, controller;
let disposed = false;

const timezone = 'Europe/Rome';
const date = computed(() => {
    const value = snapshot.value?.date;
    if (value && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return new Date(value + 'T12:00:00Z');
    }
    return clock.value;
});
const day = computed(() => new Intl.DateTimeFormat('ru-RU', { day: 'numeric', timeZone: timezone }).format(date.value));
const weekday = computed(() => new Intl.DateTimeFormat('ru-RU', { weekday: 'long', timeZone: timezone }).format(date.value));
const month = computed(() => new Intl.DateTimeFormat('ru-RU', { month: 'long', timeZone: timezone }).format(date.value));

function destination(segment) {
    const url = segment.action?.url;
    if (segment.action?.type !== 'navigate' || typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//') || url.includes('\\')) return undefined;
    try {
        const target = new URL(url, location.origin);
        return target.origin === location.origin ? target.pathname + target.search + target.hash : undefined;
    } catch { return undefined; }
}

function schedule(delay = 30000) {
    clearTimeout(timer);
    if (!disposed && !document.hidden) timer = setTimeout(refresh, delay);
}

async function refresh() {
    if (disposed || controller || document.hidden) return;
    clock.value = new Date();
    if (!navigator.onLine) {
        phase.value = 'offline';
        return;
    }
    const request = new AbortController();
    controller = request;
    const timeout = setTimeout(() => request.abort(), 10000);
    try {
        const response = await fetch(props.endpoint, {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
            cache: 'no-store', signal: request.signal, redirect: 'error',
        });
        if (disposed) return;
        if (response.status === 401 || response.status === 403) {
            snapshot.value = null;
            changed.value = new Set();
            phase.value = 'unavailable';
            return;
        }
        if (!response.ok) throw new Error('Pulse unavailable');
        const next = await response.json();
        if (disposed) return;
        if (next.schema_version !== 1 || !Array.isArray(next.segments) ||
            !next.segments.every(s => typeof s.id === 'string' && typeof s.text === 'string')) {
            throw new Error('Invalid Pulse snapshot');
        }
        const previous = snapshot.value;
        if (!previous || previous.version !== next.version) {
            const old = new Map(previous?.segments.map(s => [s.id, JSON.stringify(s)]) ?? []);
            changed.value = new Set(previous ? next.segments.filter(s => old.get(s.id) !== JSON.stringify(s)).map(s => s.id) : []);
        }
        snapshot.value = next;
        phase.value = 'ready';
        const remaining = Date.parse(next.valid_until) - Date.now();
        schedule(Number.isFinite(remaining) ? Math.max(1000, Math.min(30000, remaining)) : 30000);
    } catch {
        if (disposed) return;
        phase.value = navigator.onLine ? 'error' : 'offline';
        schedule(30000);
    } finally {
        clearTimeout(timeout);
        if (controller === request) controller = undefined;
    }
}

function onVisibility() {
    if (document.hidden) {
        clearTimeout(timer);
        return;
    }
    refresh();
}
function onOffline() {
    clearTimeout(timer);
    phase.value = 'offline';
}
onMounted(() => {
    refresh();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('online', refresh);
    window.addEventListener('offline', onOffline);
    window.addEventListener('pageshow', refresh);
});
onBeforeUnmount(() => {
    disposed = true;
    clearTimeout(timer);
    controller?.abort();
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('online', refresh);
    window.removeEventListener('offline', onOffline);
    window.removeEventListener('pageshow', refresh);
});

const icons = {
    task: 'M9 12.75 11.25 15 15 9.75M9 3.75h6A2.25 2.25 0 0 1 17.25 6v.75H19.5v14.5h-15V6.75h2.25V6A2.25 2.25 0 0 1 9 3.75Z',
    calendar: 'M6 3v4m12-4v4M3.75 9h16.5M5.25 5.25h13.5A1.5 1.5 0 0 1 20.25 6.75v12A1.5 1.5 0 0 1 18.75 20.25H5.25a1.5 1.5 0 0 1-1.5-1.5v-12a1.5 1.5 0 0 1 1.5-1.5Z',
    request: 'M8 3.75h6.75l5 5V20.25H4.25V3.75H8m6.75 0v5h5M8 12h8m-8 4h6',
    control: 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
};
</script>

<template>
    <section class="tris-pulse" aria-label="Пульс">
        <div class="tris-pulse-date">
            <div class="tris-pulse-day">
                <span>{{ day }}</span>
                <span class="tris-pulse-mascot-slot" aria-hidden="true"></span>
            </div>
            <div class="tris-pulse-date-meta">
                <span>{{ weekday }}</span>
                <span>{{ month }}</span>
            </div>
        </div>
        <div v-if="snapshot" class="tris-pulse-copy" aria-live="polite" aria-atomic="true">
            <component
                v-for="segment in snapshot.segments"
                :key="segment.id + ':' + JSON.stringify(segment)"
                :is="phase === 'ready' && destination(segment) ? 'a' : 'span'"
                :href="phase === 'ready' ? destination(segment) : undefined"
                :class="{ 'tris-pulse-accent': segment.accent, 'tris-pulse-changed': changed.has(segment.id) }"
            ><svg v-if="segment.accent && icons[segment.icon]" class="tris-pulse-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="icons[segment.icon]" /></svg>{{ segment.text }}</component>
        </div>
        <div v-else-if="phase === 'loading'" class="tris-pulse-skeleton" role="status" aria-label="Загрузка Пульса">
            <span></span><span></span><span></span>
        </div>
        <div v-else class="tris-pulse-copy" role="status">
            {{ phase === 'offline' ? 'Нет подключения к интернету.' : 'Пульс пока недоступен.' }}
        </div>
        <div v-if="phase === 'error' || phase === 'offline'" class="tris-pulse-status">
            <span v-if="snapshot">{{ phase === 'offline' ? 'Нет сети · последние данные' : 'Не удалось обновить · последние данные' }}</span>
            <button v-if="phase === 'error'" type="button" @click="refresh">Повторить</button>
        </div>
    </section>
</template>

<style scoped>
.tris-pulse {
    --pulse-muted: var(--tris-secondary, #7a7a7a);
    --pulse-ink: var(--tris-primary, #111);
    font-family: var(--tris-font, sans-serif);
    padding: 28px 24px 30px;
}
.tris-pulse-date { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
.tris-pulse-day { display: inline-flex; align-items: flex-start; gap: 6px; font-size: clamp(72px, 23vw, 104px); font-weight: 600; line-height: .84; letter-spacing: -.075em; color: var(--pulse-ink); }
.tris-pulse-mascot-slot { flex: 0 0 10px; height: 10px; margin-top: 9px; border-radius: 50%; background: #ef655c; }
.tris-pulse-date-meta { text-align: right; font-size: 16px; line-height: 1.25; color: var(--pulse-muted); }
.tris-pulse-date-meta span { display: block; text-transform: capitalize; }
.tris-pulse-date-meta span:first-child { color: var(--pulse-ink); font-weight: 500; }
.tris-pulse-copy { font-size: clamp(23px, 6.2vw, 32px); line-height: 1.29; letter-spacing: -.035em; font-weight: 500; color: var(--pulse-muted); overflow-wrap: anywhere; }
.tris-pulse-accent { color: var(--pulse-ink); font-weight: 600; }
.tris-pulse-copy a { text-decoration: none; border-radius: 4px; transition: color 100ms ease; }
.tris-pulse-copy a:active { color: var(--pulse-muted); }
.tris-pulse-copy a:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
.tris-pulse-icon { display: inline-block; width: .85em; height: .85em; vertical-align: -.045em; margin-inline-end: .16em; }
.tris-pulse-changed { animation: pulse-change 240ms ease-out both; }
@keyframes pulse-change { from { opacity: .25; } to { opacity: 1; } }
.tris-pulse-status { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; font-size: 13px; color: var(--pulse-muted); }
.tris-pulse-status button { color: var(--pulse-ink); text-decoration: underline; }
.tris-pulse-skeleton { display: grid; gap: 12px; padding-block: 6px; }
.tris-pulse-skeleton span { height: 19px; background: var(--tris-background, #f5f5f5); border-radius: 6px; }
.tris-pulse-skeleton span:nth-child(2) { width: 88%; }
.tris-pulse-skeleton span:nth-child(3) { width: 54%; }
@media (prefers-reduced-motion: reduce) {
    .tris-pulse-changed { animation: none; }
    .tris-pulse-copy a { transition: none; }
}
</style>
