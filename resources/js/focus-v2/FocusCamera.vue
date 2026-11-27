<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({ kind: { type: String, required: true } });
const emit = defineEmits(['close', 'capture', 'gallery']);
const video = ref(null);
const closeButton = ref(null);
const phase = ref('opening');
const error = ref('');
const seconds = ref(0);
let stream;
let recorder;
let timer;
let disposed = false;

function stopCamera() {
    disposed = true;
    clearInterval(timer);
    if (recorder && recorder.state !== 'inactive') {
        try { recorder.stop(); } catch (_) { /* The device may already be disconnecting. */ }
    }
    stream?.getTracks().forEach(track => track.stop());
}
function close() { stopCamera(); emit('close'); }
function fail(message) { error.value = message; phase.value = 'error'; stopCamera(); }

onMounted(async () => {
    closeButton.value?.focus();
    try {
        if (!navigator.mediaDevices?.getUserMedia) {
            fail('Камера недоступна в этом окне. Снимите материал в приложении камеры и выберите его из галереи.');
            return;
        }
        if (props.kind === 'video' && typeof MediaRecorder === 'undefined') {
            fail('Запись видео в этом окне не поддерживается. Снимите ролик в приложении камеры.');
            return;
        }
        const result = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1600 }, height: { ideal: 1200 } },
            audio: props.kind === 'video',
        });
        if (disposed) {
            result.getTracks().forEach(track => track.stop());
            return;
        }
        stream = result;
        for (const track of stream.getTracks()) track.addEventListener('ended', () => {
            if (!disposed) fail('Камера отключилась. Откройте её снова или воспользуйтесь галереей.');
        });
        await nextTick();
        if (disposed) return;
        video.value.srcObject = stream;
        await video.value.play();
        if (!disposed) phase.value = 'ready';
    } catch (cause) {
        if (!disposed) fail(cause.name === 'NotAllowedError'
            ? 'Нет доступа к камере или микрофону. Разрешите доступ в Telegram или снимите материал отдельно и выберите его из галереи.'
            : 'Не удалось запустить камеру. Снимите материал отдельно и выберите его из галереи.');
    }
});
function pickGallery(event) {
    const files = Array.from(event.target.files ?? []);
    if (files.length) emit('gallery', files);
}
onBeforeUnmount(stopCamera);

function deliver(blob) {
    if (disposed) return;
    if (!blob?.size) { fail('Не удалось сохранить съёмку. Попробуйте ещё раз.'); return; }
    const type = blob.type.split(';')[0].toLowerCase();
    const extension = type === 'video/mp4' ? 'mp4' : type === 'video/webm' ? 'webm' : 'jpg';
    const file = new File([blob], (props.kind === 'video' ? 'Видео-' : 'Фото-') + Date.now() + '.' + extension, { type });
    stopCamera();
    emit('capture', file);
}

function take() {
    if (phase.value !== 'ready' || !video.value?.videoWidth || !video.value?.videoHeight) return;
    if (props.kind === 'image') {
        const canvas = document.createElement('canvas');
        const scale = Math.min(1, 1920 / Math.max(video.value.videoWidth, video.value.videoHeight));
        canvas.width = Math.round(video.value.videoWidth * scale);
        canvas.height = Math.round(video.value.videoHeight * scale);
        const context = canvas.getContext('2d');
        if (!context) { fail('Не удалось сделать снимок.'); return; }
        context.drawImage(video.value, 0, 0, canvas.width, canvas.height);
        phase.value = 'finishing';
        canvas.toBlob(deliver, 'image/jpeg', 0.87);
        return;
    }
    try {
        const mimeType = ['video/mp4', 'video/webm;codecs=vp8,opus', 'video/webm']
            .find(type => MediaRecorder.isTypeSupported(type));
        if (!mimeType) { fail('Устройство не поддерживает запись в MP4 или WebM. Выберите ролик из галереи.'); return; }
        recorder = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: 1600000 });
        const chunks = [];
        recorder.ondataavailable = event => { if (!disposed && event.data.size) chunks.push(event.data); };
        recorder.onerror = () => { if (!disposed) fail('Запись не удалась. Попробуйте ещё раз.'); };
        recorder.onstop = () => deliver(new Blob(chunks, { type: recorder.mimeType || mimeType }));
        recorder.start(1000);
        phase.value = 'recording';
        const started = Date.now();
        timer = setInterval(() => {
            seconds.value = Math.floor((Date.now() - started) / 1000);
            if (seconds.value >= 60) finish();
        }, 200);
    } catch (_) {
        fail('Запись не запустилась. Повторите попытку или выберите ролик из галереи.');
    }
}
function finish() {
    if (phase.value !== 'recording') return;
    clearInterval(timer);
    phase.value = 'finishing';
    if (recorder.state !== 'inactive') recorder.stop();
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/80 p-3 sm:items-center"
        role="dialog" aria-modal="true" :aria-label="kind === 'image' ? 'Камера для фото' : 'Камера для видео'" @keydown.esc.stop="close">
        <section class="max-h-[90dvh] w-full max-w-lg overflow-auto rounded-2xl bg-white">
            <header class="flex items-center justify-between px-4 py-3">
                <h2 class="font-bold">{{ kind === 'image' ? 'Камера для фото' : 'Камера для видео' }}</h2>
                <button ref="closeButton" type="button" class="grid size-11 place-items-center rounded-full bg-slate-100" aria-label="Закрыть камеру" @click="close">✕</button>
            </header>
            <video v-show="!error" ref="video" autoplay muted playsinline class="h-[42dvh] w-full bg-slate-950 object-contain"></video>
            <div v-if="error" class="space-y-3 p-4">
                <p class="text-sm text-red-800" role="alert">{{ error }}</p>
                <label class="inline-flex min-h-11 cursor-pointer items-center rounded-xl bg-blue-700 px-4 text-sm font-semibold text-white focus-within:ring-2 focus-within:ring-blue-700">
                    Выбрать из галереи
                    <input type="file" multiple class="sr-only" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm" @change="pickGallery">
                </label>
            </div>
            <p v-else-if="phase === 'opening'" class="p-4 text-sm" role="status">Открываем камеру. Разрешите доступ, если появится запрос.</p>
            <p v-else-if="phase === 'finishing'" class="p-4 text-sm" role="status">Готовим предпросмотр…</p>
            <div v-else class="space-y-2 p-4">
                <p v-if="kind === 'video'" class="text-sm text-slate-600">{{ phase === 'recording' ? 'Запись · ' + seconds + ' из 60 сек' : 'До 60 секунд. Более длинный ролик можно выбрать из галереи.' }}</p>
                <button v-if="phase === 'recording'" type="button" class="min-h-12 w-full rounded-xl bg-red-700 px-4 font-semibold text-white" @click="finish">Остановить запись</button>
                <button v-else type="button" class="min-h-12 w-full rounded-xl bg-blue-700 px-4 font-semibold text-white" @click="take">{{ kind === 'image' ? 'Сделать снимок' : 'Начать запись' }}</button>
            </div>
        </section>
    </div>
</template>

