<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { allowedFocusFile, focusMediaRequest, transferFocusFile } from './media-upload';

const props = defineProps({
    bootstrapUrl: { type: String, required: true },
    draftUrl: { type: String, required: true },
    mediaStartUrl: { type: String, required: true },
    classicUrl: { type: String, required: true },
});

const loading = ref(true);
const loadError = ref('');
const syncError = ref('');
const state = ref('idle');
const control = ref(null);
const run = ref(null);
const rooms = ref([]);
const people = ref([]);
const apartments = ref([]);
const setup = ref({});
const answers = ref([]);
const media = ref([]);
const stage = ref('setup');
const activeRoom = ref(0);
const blockedQuestion = ref(null);
const uploads = ref([]);
const busyUploads = computed(() => uploads.value.some(item => ['queued', 'uploading', 'attaching', 'error'].includes(item.status)));
let activeTransfers = 0;
let attachInFlight = false;
let attachQueue = Promise.resolve();

let changeSerial = 0;
let acknowledgedSerial = 0;
let timer = null;
let inFlight = null;
let uncertainSnapshot = null;

const statusLabel = computed(() => ({
    idle: 'Готово',
    dirty: 'Не сохранено',
    saving: 'Сохраняем…',
    synced: 'Сохранено',
    error: 'Ошибка сохранения',
    conflict: 'Конфликт черновика',
})[state.value] ?? 'Ожидание');

const totalRequired = computed(() => rooms.value.reduce((sum, room, roomIndex) => (
    sum + (room.items ?? []).filter(question => !room.is_optional && !question.is_optional).length
), 0));
const completedRequired = computed(() => rooms.value.reduce((sum, room, roomIndex) => (
    sum + (room.items ?? []).filter((question, questionIndex) =>
        !room.is_optional && !question.is_optional && filled(roomIndex, questionIndex)).length
), 0));
const currentRoom = computed(() => rooms.value[activeRoom.value] ?? { title: '', items: [] });
const canEdit = computed(() => Boolean(run.value && !run.value.schema_conflict && state.value !== 'conflict'));

const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function fetchJson(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf } : {}),
        },
        ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const firstError = Object.values(data.errors ?? {}).flat().find(message => typeof message === 'string');
        const exception = new Error(firstError || data.message || (response.status === 419
            ? 'Сессия истекла. Войдите снова.' : 'Сервер не ответил.'));
        exception.status = response.status;
        exception.code = data.error;
        throw exception;
    }
    return data;
}

async function initialize() {
    loading.value = true;
    loadError.value = '';
    try {
        const data = await fetchJson(props.bootstrapUrl);
        control.value = data.control;
        run.value = data.run;
        rooms.value = data.rooms ?? [];
        people.value = data.people ?? [];
        apartments.value = data.apartments ?? [];
        setup.value = data.setup ?? {};
        answers.value = data.answers ?? [];
        media.value = data.media ?? [];
        if (data.run?.schema_conflict) {
            state.value = 'conflict';
            syncError.value = 'У найденного черновика другая схема вопросов. Старая версия сохранена отдельно; изменения заблокированы.';
        } else {
            state.value = 'idle';
        }
        stage.value = 'setup';
    } catch (error) {
        loadError.value = error.message || 'Не удалось загрузить контроль.';
    } finally {
        loading.value = false;
    }
}

function filled(room, question) {
    const answer = answers.value[room]?.[question];
    return Boolean(String(answer?.selected ?? '').trim() || String(answer?.custom ?? '').trim());
}

function roomCompleted(index) {
    return (rooms.value[index]?.items ?? []).filter((question, qIndex) =>
        !(rooms.value[index]?.is_optional || question.is_optional) && filled(index, qIndex)).length;
}

function roomRequired(index) {
    return (rooms.value[index]?.items ?? []).filter(question =>
        !(rooms.value[index]?.is_optional || question.is_optional)).length;
}

function isNegative(question, selected) {
    const options = question?.answer_options_scored ?? [];
    if (!selected || !['options', 'both'].includes(question.answer_type ?? 'options') || !options.length) return false;
    const maximum = Math.max(0, ...options.map(option => Number(option.points ?? 0)));
    const points = options.reduce((result, option, index) => {
        const matches = [option.value ?? option.label ?? '', option.label ?? '', 'option_' + index];
        return matches.map(String).includes(String(selected).trim()) ? Number(option.points ?? 0) : result;
    }, 0);
    return maximum > 0 && points < maximum;
}

function optionValue(option, index) {
    return String(option.value ?? option.label ?? 'option_' + index);
}

function changeAnswer(room, index, value) {
    const answer = answers.value[room][index];
    if (answer.selected === value) return;
    answer.selected = value;
    if (!isNegative(rooms.value[room].items[index], value)) {
        answer.corrective = { repeats: null, action: '', recheck: null };
    }
    touch();
}

function touch() {
    if (!canEdit.value) return;
    changeSerial++;
    state.value = 'dirty';
    syncError.value = '';
    queueSave();
}

function queueSave(delay = 800) {
    clearTimeout(timer);
    timer = setTimeout(() => { void flush(); }, delay);
}

function takeSnapshot() {
    return {
        control_id: control.value.id,
        run_id: run.value.id,
        schema_version: control.value.schema_version,
        draft_id: run.value.draft_id,
        expected_revision: run.value.revision,
        setup: JSON.parse(JSON.stringify(setup.value)),
        answers: JSON.parse(JSON.stringify(answers.value)),
    };
}

async function flush() {
    clearTimeout(timer);
    timer = null;
    if (!canEdit.value) return false;
    if (attachInFlight) { queueSave(450); return false; }
    if (inFlight) return inFlight;
    if (!uncertainSnapshot && changeSerial === acknowledgedSerial) return true;

    const snapshot = uncertainSnapshot ?? takeSnapshot();
    const serial = uncertainSnapshot ? uncertainSnapshot.serial : changeSerial;
    const body = uncertainSnapshot ? uncertainSnapshot.body : snapshot;
    state.value = 'saving';

    inFlight = (async () => {
        try {
            const result = await fetchJson(props.draftUrl, {
                method: 'POST',
                body: JSON.stringify(body),
            });
            run.value = { ...run.value, ...result.run };
            uncertainSnapshot = null;
            acknowledgedSerial = Math.max(acknowledgedSerial, serial);
            state.value = changeSerial === acknowledgedSerial ? 'synced' : 'dirty';
            syncError.value = '';
            return true;
        } catch (error) {
            // Retry the identical body before sending any newer edits. The backend
            // detects a lost acknowledgement and returns the original revision.
            uncertainSnapshot = error.status === 422 ? null : { body, serial };
            state.value = error.status === 409 ? 'conflict' : 'error';
            syncError.value = error.message || 'Не удалось сохранить изменения.';
            return false;
        } finally {
            inFlight = null;
            if (state.value === 'dirty') queueSave(0);
        }
    })();
    return inFlight;
}

async function ensureSynced() {
    clearTimeout(timer);
    for (let attempt = 0; attempt < 5; attempt++) {
        const ok = await flush();
        if (!ok) return false;
        if (changeSerial === acknowledgedSerial && !inFlight) return true;
    }
    return false;
}

function firstMissing() {
    for (let roomIndex = 0; roomIndex < rooms.value.length; roomIndex++) {
        const room = rooms.value[roomIndex];
        for (let questionIndex = 0; questionIndex < (room.items ?? []).length; questionIndex++) {
            if (!room.is_optional && !room.items[questionIndex].is_optional && !filled(roomIndex, questionIndex)) {
                return { roomIndex, questionIndex };
            }
        }
    }
    return null;
}

function goRoom(index) {
    if (index >= 0 && index < rooms.value.length) {
        activeRoom.value = index;
        blockedQuestion.value = null;
        stage.value = 'questions';
        window.scrollTo({ top: 0, behavior: 'instant' });
    }
}

function nextRoom() {
    if (activeRoom.value + 1 < rooms.value.length) {
        goRoom(activeRoom.value + 1);
        return;
    }
    openReview();
}

function openReview() {
    const missing = firstMissing();
    if (missing) {
        goRoom(missing.roomIndex);
        blockedQuestion.value = missing.questionIndex;
        return;
    }
    stage.value = 'review';
}

async function openClassic() {
    if (busyUploads.value) {
        syncError.value = 'Дождитесь завершения загрузки файлов или отмените их.';
        return;
    }
    if (!run.value || state.value === 'conflict') {
        window.location.assign(props.classicUrl);
        return;
    }
    const ok = await ensureSynced();
    if (ok) window.location.assign(props.classicUrl);
}


function pendingAt(room, question) {
    return uploads.value.filter(item => item.room === room && item.question === question
        && ['queued', 'uploading', 'attaching'].includes(item.status));
}

function addFiles(event, room, question) {
    const selected = Array.from(event.target.files ?? []);
    event.target.value = '';
    if (!canEdit.value) return;

    const current = media.value[room]?.[question]?.length ?? 0;
    let reserved = pendingAt(room, question).length;
    for (const file of selected) {
        const error = allowedFocusFile(file);
        if (current + reserved >= 12) {
            uploads.value.push({ id: crypto.randomUUID(), file, room, question, status: 'error',
                progress: 0, error: 'Не больше 12 вложений на один вопрос.' });
            continue;
        }
        const id = crypto.randomUUID();
        uploads.value.push({
            id, file, room, question, status: error ? 'error' : 'queued',
            progress: 0, error, controller: null, info: null,
        });
        if (!error) reserved++;
    }
    pumpTransfers();
}

function pumpTransfers() {
    while (activeTransfers < 2 && canEdit.value) {
        const next = uploads.value.find(item => item.status === 'queued');
        if (!next) break;
        next.status = 'uploading';
        activeTransfers++;
        void sendOne(next).finally(() => {
            activeTransfers--;
            pumpTransfers();
        });
    }
}

async function sendOne(item) {
    const controller = new AbortController();
    item.controller = controller;
    item.error = '';
    try {
        // A draft must exist before the first media transfer.
        if (!run.value?.draft_id) touch();
        if (!(await ensureSynced())) throw new Error('Сначала сохраните ответы. Затем повторите загрузку.');

        const result = await transferFocusFile({
            startUrl: props.mediaStartUrl,
            file: item.file,
            uploadId: item.id,
            draft: {
                control_id: control.value.id,
                draft_id: run.value.draft_id,
                run_id: run.value.id,
                schema_version: control.value.schema_version,
            },
            room: item.room,
            question: item.question,
            csrf,
            signal: controller.signal,
            onProgress: progress => { item.progress = progress; },
            onInit: info => { item.info = info; },
        });
        if (controller.signal.aborted) return;
        item.status = 'attaching';

        const task = attachQueue.catch(() => {}).then(async () => {
            if (!(await ensureSynced())) throw new Error('Ответы не сохранены. Повторите прикрепление.');
            attachInFlight = true;
            try {
                const attached = await focusMediaRequest(result.urls.attach, 'POST', {
                    draft_id: run.value.draft_id,
                    expected_revision: run.value.revision,
                }, csrf, controller.signal);
                run.value = { ...run.value, ...attached.run };
                const arr = media.value[item.room] ?? (media.value[item.room] = []);
                const files = arr[item.question] ?? (arr[item.question] = []);
                if (!files.some(file => file.upload_id === attached.media_item.upload_id)) {
                    files.push(attached.media_item);
                }
            } finally {
                attachInFlight = false;
                if (changeSerial !== acknowledgedSerial) queueSave(0);
            }
        });
        attachQueue = task.catch(() => {});
        await task;
        uploads.value = uploads.value.filter(other => other.id !== item.id);
    } catch (error) {
        if (controller.signal.aborted) {
            item.status = 'cancelled';
            return;
        }
        item.status = 'error';
        item.error = error.message || 'Не удалось загрузить файл.';
        if (error.status === 409 && item.progress === 100) {
            syncError.value = 'Не удалось прикрепить файл из-за изменения черновика. Проверьте ответы перед повтором.';
        }
    }
}

function retryFile(item) {
    if (!canEdit.value || item.status !== 'error' || !item.file) return;
    item.status = 'queued';
    item.error = '';
    pumpTransfers();
}

async function cancelFile(item) {
    if (!['queued', 'uploading', 'error'].includes(item.status)) return;

    // If attach succeeded but its response was lost, deleting from the
    // client must not falsely claim that the evidence was removed.
    if (item.status === 'error' && item.info?.urls?.status) {
        try {
            const current = await focusMediaRequest(item.info.urls.status, 'GET', undefined, csrf);
            if (current.status === 'attached') {
                item.error = 'Файл уже прикреплён на сервере. Нажмите «Повторить», чтобы восстановить подтверждение.';
                return;
            }
        } catch (ignored) {
            // A pending session is still eligible for server-side pruning.
        }
    }
    item.controller?.abort();
    item.status = 'cancelled';
    if (item.info?.urls?.cancel) {
        try {
            await focusMediaRequest(item.info.urls.cancel, 'DELETE', undefined, csrf);
        } catch (error) {
            if (error.status === 409) {
                item.status = 'error';
                item.error = 'Статус файла изменился на сервере. Повторите загрузку для синхронизации.';
            }
        }
    }
}

function dismissFile(item) {
    if (['queued', 'uploading', 'attaching'].includes(item.status)) return;
    uploads.value = uploads.value.filter(other => other.id !== item.id);
}

function warnBeforeUnload(event) {
    if (busyUploads.value || state.value === 'dirty' || state.value === 'saving' || state.value === 'error') {
        event.preventDefault();
        event.returnValue = '';
    }
}

onMounted(() => {
    window.addEventListener('beforeunload', warnBeforeUnload);
    void initialize();
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    window.removeEventListener('beforeunload', warnBeforeUnload);
});
</script>

<template>
    <div class="min-h-screen bg-[#EDF0F4] text-[#18283D]">
        <div class="mx-auto min-h-screen max-w-2xl px-3 pb-12 pt-5 sm:px-6">
            <header class="mb-5 flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#4274A3]">TRIS Academy · Beta</p>
                    <h1 class="mt-1 text-2xl font-extrabold tracking-tight">Focus Control V2</h1>
                </div>
                <a :href="classicUrl" class="rounded-full border border-[#D7E0EA] bg-white px-4 py-2 text-xs font-bold text-[#325B82]" @click.prevent="openClassic">Старый Focus</a>
            </header>

            <div v-if="loading" role="status" class="rounded-3xl bg-white p-7 text-center text-sm">Загружаем вопросы…</div>
            <div v-else-if="loadError" role="alert" class="rounded-3xl bg-white p-5">
                <p class="font-bold text-red-700">{{ loadError }}</p>
                <button type="button" class="mt-3 rounded-xl bg-[#285B86] px-4 py-2 font-semibold text-white" @click="initialize">Повторить</button>
            </div>

            <template v-else>
                <div class="mb-4 flex items-center justify-between gap-2 rounded-2xl bg-white px-4 py-3 shadow-sm">
                    <div class="flex items-center gap-2 text-sm" role="status" aria-live="polite">
                        <span class="size-2.5 rounded-full" :class="state === 'synced' || state === 'idle' ? 'bg-emerald-500' : state === 'saving' ? 'bg-blue-500' : state === 'dirty' ? 'bg-amber-500' : 'bg-red-500'"></span>
                        <strong>{{ statusLabel }}</strong>
                    </div>
                    <button type="button" :disabled="!canEdit || state === 'saving'" class="rounded-xl bg-[#EAF2FB] px-3 py-2 text-xs font-bold text-[#285B86] disabled:opacity-50" @click="flush">
                        {{ state === 'error' ? 'Повторить' : 'Сохранить' }}
                    </button>
                </div>

                <div v-if="syncError" role="alert" class="mb-4 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ syncError }}
                    <p v-if="state === 'conflict'" class="mt-2 font-semibold">Не перезагружайте страницу, если есть несохранённые ответы. Для восстановления сначала разберите конфликт черновика.</p>
                </div>

                <template v-if="stage === 'setup'">
                    <section class="space-y-4 rounded-3xl bg-white p-5 shadow-sm">
                        <div>
                            <h2 class="text-xl font-bold">Данные проверки</h2>
                            <p class="mt-1 text-sm text-slate-500">{{ control?.name }}</p>
                        </div>

                        <label class="block text-sm font-semibold">
                            Сотрудник
                            <select v-model="setup.cleaner_id" :disabled="!canEdit" class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white p-3 text-base" @change="touch">
                                <option :value="null">Выбрать сотрудника</option>
                                <option v-for="person in people" :key="person.id" :value="person.id">{{ person.name }}</option>
                            </select>
                        </label>
                        <label class="block text-sm font-semibold">
                            Квартира
                            <select v-model="setup.apartment_id" :disabled="!canEdit" class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white p-3 text-base" @change="touch">
                                <option :value="null">Выбрать квартиру</option>
                                <option v-for="apartment in apartments" :key="apartment.id" :value="apartment.id">{{ apartment.name }}</option>
                            </select>
                        </label>

                        <div class="grid grid-cols-2 gap-3">
                            <label class="text-sm font-semibold">Дата уборки
                                <input v-model="setup.cleaning_date" type="date" :disabled="!canEdit" class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white p-3 text-base" @change="touch">
                            </label>
                            <label class="text-sm font-semibold">Дата проверки
                                <input v-model="setup.inspection_date" type="date" :disabled="!canEdit" class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white p-3 text-base" @change="touch">
                            </label>
                        </div>
                        <label class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm font-medium">
                            <input v-model="setup.is_assigned" type="checkbox" :disabled="!canEdit" @change="touch">
                            Назначенная уборка
                        </label>
                        <label class="block text-sm font-semibold">Предыдущий сотрудник
                            <input v-model="setup.previous_cleaner" type="text" maxlength="255" :disabled="!canEdit" class="mt-1.5 w-full rounded-xl border border-slate-300 p-3 text-base" @input="touch">
                        </label>
                        <label class="block text-sm font-semibold">Комментарий
                            <textarea v-model="setup.comment" rows="2" maxlength="2000" :disabled="!canEdit" class="mt-1.5 w-full rounded-xl border border-slate-300 p-3 text-base" @input="touch"></textarea>
                        </label>
                        <button type="button" :disabled="!canEdit" class="w-full rounded-2xl bg-[#285B86] p-4 text-base font-bold text-white disabled:opacity-50" @click="goRoom(0)">
                            К вопросам
                        </button>
                    </section>
                </template>

                <template v-else-if="stage === 'questions'">
                    <section class="mb-4 rounded-3xl bg-white p-4 shadow-sm">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <strong class="text-sm">Заполнено {{ completedRequired }} из {{ totalRequired }}</strong>
                            <span class="text-xs text-slate-500">{{ rooms.length }} комнат</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" :aria-valuenow="completedRequired" :aria-valuemax="totalRequired" aria-valuemin="0">
                            <div class="h-full rounded-full bg-[#3181BB] transition-all" :style="{ width: (totalRequired ? completedRequired / totalRequired * 100 : 100) + '%' }"></div>
                        </div>
                        <nav class="mt-4 flex gap-2 overflow-x-auto pb-1" aria-label="Комнаты">
                            <button v-for="(room, index) in rooms" :key="index" type="button" class="shrink-0 rounded-xl px-3 py-2 text-xs font-semibold" :class="activeRoom === index ? 'bg-[#285B86] text-white' : 'bg-[#E9EEF4] text-[#305578]'" :aria-current="activeRoom === index ? 'step' : undefined" @click="goRoom(index)">
                                {{ room.title || 'Комната ' + (index + 1) }} · {{ roomCompleted(index) }}/{{ roomRequired(index) }}
                            </button>
                        </nav>
                    </section>

                    <div class="mb-4 flex items-start justify-between gap-2 px-1">
                        <h2 class="text-2xl font-extrabold">{{ currentRoom.title }}</h2>
                        <span class="whitespace-nowrap text-xs font-semibold text-[#4274A3]">{{ activeRoom + 1 }}/{{ rooms.length }}</span>
                    </div>

                    <div class="space-y-3">
                        <article v-for="(question, questionIndex) in currentRoom.items" :key="activeRoom + '-' + questionIndex" class="rounded-3xl border bg-white p-4 shadow-sm" :class="blockedQuestion === questionIndex ? 'border-red-400' : 'border-transparent'">
                            <div class="flex justify-between gap-3">
                                <h3 class="text-base font-bold leading-snug">{{ question.question || 'Вопрос' }}</h3>
                                <span v-if="currentRoom.is_optional || question.is_optional" class="shrink-0 text-xs text-slate-500">Необязательно</span>
                            </div>
                            <p v-if="blockedQuestion === questionIndex" class="mt-2 text-xs font-semibold text-red-600">Нужно ответить на этот вопрос.</p>
                            <img v-if="question.question_image_url" :src="question.question_image_url" alt="" class="mt-3 max-h-48 rounded-xl object-contain">

                            <div v-if="['options', 'both'].includes(question.answer_type ?? 'options')" class="mt-4 grid gap-2">
                                <button v-for="(option, optionIndex) in question.answer_options_scored ?? []" :key="optionIndex" type="button" :disabled="!canEdit"
                                    class="min-h-12 rounded-xl border px-4 py-3 text-left text-sm font-semibold disabled:opacity-50"
                                    :class="answers[activeRoom][questionIndex].selected === optionValue(option, optionIndex) ? 'border-[#285B86] bg-[#EAF2FB] text-[#194C75]' : 'border-[#E1E6EC] bg-[#F6F8FA] text-[#2D4054]'"
                                    @click="changeAnswer(activeRoom, questionIndex, optionValue(option, optionIndex))">
                                    <span class="mr-2">{{ answers[activeRoom][questionIndex].selected === optionValue(option, optionIndex) ? '●' : '○' }}</span>
                                    {{ option.label ?? option.value }}
                                </button>
                            </div>

                            <label v-if="['text', 'both'].includes(question.answer_type ?? 'options')" class="mt-3 block text-xs font-semibold text-slate-600">
                                {{ question.answer_type === 'both' ? question.custom_answer_label || 'Комментарий' : 'Ответ' }}
                                <textarea v-model="answers[activeRoom][questionIndex].custom" :disabled="!canEdit" rows="2" maxlength="2000" class="mt-2 w-full rounded-xl border border-slate-300 p-3 text-base" @input="touch"></textarea>
                            </label>

                            <section v-if="isNegative(question, answers[activeRoom][questionIndex].selected)" class="mt-4 space-y-3 rounded-xl bg-amber-50 p-3">
                                <p class="text-sm font-bold text-amber-950">Уточнение по замечанию</p>
                                <label class="block text-xs font-semibold text-amber-950">Повторяется?
                                    <select v-model="answers[activeRoom][questionIndex].corrective.repeats" :disabled="!canEdit" class="mt-1 w-full rounded-lg border border-amber-200 bg-white p-2 text-sm" @change="touch">
                                        <option :value="null">Не указано</option><option :value="true">Да</option><option :value="false">Нет</option>
                                    </select>
                                </label>
                                <label class="block text-xs font-semibold text-amber-950">Что сделать
                                    <textarea v-model="answers[activeRoom][questionIndex].corrective.action" :disabled="!canEdit" rows="2" maxlength="2000" class="mt-1 w-full rounded-lg border border-amber-200 bg-white p-2 text-sm" @input="touch"></textarea>
                                </label>
                                <label class="block text-xs font-semibold text-amber-950">Повторная проверка?
                                    <select v-model="answers[activeRoom][questionIndex].corrective.recheck" :disabled="!canEdit" class="mt-1 w-full rounded-lg border border-amber-200 bg-white p-2 text-sm" @change="touch">
                                        <option :value="null">Не указано</option><option :value="true">Да</option><option :value="false">Нет</option>
                                    </select>
                                </label>
                            </section>

                            <div class="mt-4 border-t border-slate-100 pt-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-bold text-slate-500">Фото и видео · {{ (media[activeRoom]?.[questionIndex] ?? []).length + pendingAt(activeRoom, questionIndex).length }}/12</p>
                                    <label class="cursor-pointer rounded-xl bg-[#EAF2FB] px-3 py-2 text-xs font-bold text-[#285B86]" :class="!canEdit ? 'pointer-events-none opacity-50' : ''">
                                        + Добавить
                                        <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm" class="sr-only" :disabled="!canEdit" @change="addFiles($event, activeRoom, questionIndex)">
                                    </label>
                                </div>
                                <div v-if="(media[activeRoom]?.[questionIndex] ?? []).length" class="mt-2 flex flex-wrap gap-2">
                                    <a v-for="file in media[activeRoom][questionIndex]" :key="file.upload_id || file.index" :href="file.url" target="_blank" rel="noopener noreferrer" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-[#285B86]">{{ file.name || 'Вложение' }}</a>
                                </div>
                                <div v-for="item in uploads.filter(file => file.room === activeRoom && file.question === questionIndex)" :key="item.id" class="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="min-w-0 truncate font-semibold">{{ item.file?.name }}</span>
                                        <span class="shrink-0 text-slate-500">{{ item.status === 'queued' ? 'В очереди' : item.status === 'uploading' ? 'Загрузка' : item.status === 'attaching' ? 'Прикрепляем' : item.status === 'cancelled' ? 'Отменено' : 'Ошибка' }}</span>
                                    </div>
                                    <div v-if="['uploading', 'attaching'].includes(item.status)" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                        <div class="h-full bg-[#3181BB]" :style="{ width: item.progress + '%' }"></div>
                                    </div>
                                    <p v-if="item.error" role="alert" class="mt-2 text-red-700">{{ item.error }}</p>
                                    <div class="mt-2 flex gap-2">
                                        <button v-if="item.status === 'error'" type="button" class="rounded-lg bg-[#285B86] px-3 py-1.5 font-semibold text-white" @click="retryFile(item)">Повторить</button>
                                        <button v-if="['queued', 'uploading', 'error'].includes(item.status)" type="button" class="rounded-lg bg-white px-3 py-1.5 font-semibold text-slate-700" @click="cancelFile(item)">Отменить</button>
                                        <button v-else type="button" class="rounded-lg bg-white px-3 py-1.5 font-semibold text-slate-700" @click="dismissFile(item)">Скрыть</button>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="sticky bottom-0 z-10 mt-4 grid grid-cols-2 gap-2 rounded-2xl bg-white/95 p-3 shadow-lg">
                        <button type="button" class="rounded-xl bg-slate-100 p-3 text-sm font-bold text-slate-700" @click="activeRoom === 0 ? stage = 'setup' : goRoom(activeRoom - 1)">Назад</button>
                        <button type="button" class="rounded-xl bg-[#285B86] p-3 text-sm font-bold text-white" @click="nextRoom">
                            {{ activeRoom === rooms.length - 1 ? 'К сводке' : 'Далее' }}
                        </button>
                    </div>
                </template>

                <template v-else-if="stage === 'review'">
                    <section class="space-y-4 rounded-3xl bg-white p-5 shadow-sm">
                        <p class="text-xs font-bold uppercase tracking-wider text-[#4274A3]">Проверка ответов</p>
                        <h2 class="text-2xl font-extrabold">Все вопросы заполнены</h2>
                        <p class="text-sm text-slate-600">{{ completedRequired }} из {{ totalRequired }} обязательных вопросов. Сохраните ответы перед завершением.</p>
                        <div v-for="(room, index) in rooms" :key="index" class="flex items-center justify-between border-b border-slate-100 py-2">
                            <span class="text-sm font-semibold">{{ room.title }}</span>
                            <span class="text-sm font-bold text-[#285B86]">{{ roomCompleted(index) }}/{{ roomRequired(index) }}</span>
                        </div>
                        <div class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
                            Это тестовый интерфейс с независимой загрузкой медиа. Итоговая отправка пока выполняется в действующем Focus.
                            Пока проверку можно завершить в действующем Focus: он восстановит сохранённые ответы.
                        </div>
                        <button type="button" :disabled="!canEdit" class="w-full rounded-xl bg-[#285B86] p-4 text-sm font-bold text-white disabled:opacity-50" @click="openClassic">
                            Сохранить и завершить в старом Focus
                        </button>
                        <button type="button" class="w-full rounded-xl bg-slate-100 p-3 text-sm font-bold" @click="goRoom(rooms.length - 1)">Вернуться к вопросам</button>
                    </section>
                </template>
            </template>
        </div>
    </div>
</template>
