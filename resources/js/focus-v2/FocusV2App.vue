<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FocusSearchSelect from './FocusSearchSelect.vue';
import { allowedFocusFile, canRetryFocusFile, focusFileMime, focusMediaRequest, transferFocusFile } from './media-upload';
import { chooseLocalRecovery, createLocalRecoveryQueue, deleteLocalRecovery, readLocalRecovery, recoveryScope, writeLocalRecovery } from './local-recovery';

const props = defineProps({
    bootstrapUrl: { type: String, required: true },
    draftUrl: { type: String, required: true },
    submitUrl: { type: String, required: true },
    reviewStatusUrl: { type: String, default: '' },
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
const submitting = ref(false);
const submitError = ref('');
const finalResult = ref(null);
const uploads = ref([]);
const interruptedUploads = ref([]);
const viewerRole = ref('');
const testMode = ref(false);
const pickerFor = ref(null);
const mediaError = ref('');
const recoveryRecord = ref(null);
const recoveryMode = ref('');
const recoveryBusy = ref(false);
const recoveryError = ref('');
const localStorageAvailable = ref(true);
let recoveryKey = null;
const localWriter = createLocalRecoveryQueue(async operation => {
    if (operation.type === 'delete') {
        await deleteLocalRecovery(operation.scope);
    } else {
        await writeLocalRecovery(operation.record);
    }
});
function queueLocalWrite(operation) {
    const pending = localWriter(operation);
    pending.catch(() => { localStorageAvailable.value = false; });
    return pending;
}

function saveLocalSnapshot() {
    if (testMode.value) return;
    if (!recoveryKey || !run.value || !control.value || recoveryMode.value || state.value === 'conflict') return;
    void queueLocalWrite({
        type: 'write',
        record: {
            scope: recoveryKey,
            saved_at: Date.now(),
            run_id: run.value.id,
            draft_id: run.value.draft_id,
            base_revision: run.value.revision,
            setup: JSON.parse(JSON.stringify(setup.value)),
            answers: JSON.parse(JSON.stringify(answers.value)),
        },
    });
}
function settleLocalSnapshot() {
    if (testMode.value || !recoveryKey) return;
    if (changeSerial === acknowledgedSerial) {
        void queueLocalWrite({ type: 'delete', scope: recoveryKey });
    } else {
        saveLocalSnapshot();
    }
}
async function keepServerVersion() {
    if (!recoveryKey || recoveryBusy.value) return;
    recoveryBusy.value = true;
    recoveryError.value = '';
    try {
        await queueLocalWrite({ type: 'delete', scope: recoveryKey });
        recoveryRecord.value = null;
        recoveryMode.value = '';
    } catch (error) {
        recoveryError.value = 'Не удалось удалить локальную копию. Проверьте разрешения браузера.';
    } finally {
        recoveryBusy.value = false;
    }
}
function restoreLocalVersion() {
    if (recoveryMode.value !== 'safe' || !recoveryRecord.value) return;
    const saved = recoveryRecord.value;
    if (run.value.draft_id == null) {
        run.value = { ...run.value, id: saved.run_id };
    }
    setup.value = JSON.parse(JSON.stringify(saved.setup));
    answers.value = JSON.parse(JSON.stringify(saved.answers));
    recoveryRecord.value = null;
    recoveryMode.value = '';
    touch();
}
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
const canEdit = computed(() => Boolean(run.value && !run.value.schema_conflict && state.value !== 'conflict' && !recoveryMode.value));
const peopleOptions = computed(() => viewerRole.value === 'admin'
    ? [{ id: '__test__', name: 'Тестовый сотрудник · без сохранения', thumbnail: null }, ...people.value]
    : people.value);
const outstandingMedia = computed(() => interruptedUploads.value.length + uploads.value.filter(item =>
    ['queued', 'uploading', 'attaching', 'error'].includes(item.status)).length);
const canSubmitMedia = computed(() => testMode.value || outstandingMedia.value === 0);

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
    testMode.value = false;
    interruptedUploads.value = [];
    pickerFor.value = null;
    mediaError.value = '';
    cleanupPreviews();
    uploads.value = [];
    try {
        if (props.reviewStatusUrl) {
            finalResult.value = await fetchJson(props.reviewStatusUrl);
            stage.value = 'done';
            state.value = 'synced';
            return;
        }
        const data = await fetchJson(props.bootstrapUrl);
        viewerRole.value = data.viewer?.role ?? '';
        interruptedUploads.value = data.pending_uploads ?? [];
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
            recoveryKey = recoveryScope(data.viewer?.id, data.control?.id, data.control?.schema_version);
            if (recoveryKey) {
                try {
                    const saved = await readLocalRecovery(recoveryKey);
                    const shapeMatches = saved && rooms.value.every((room, index) => (
                        Array.isArray(saved.answers?.[index])
                        && saved.answers[index].length === (room.items ?? []).length
                    ));
                    const decision = shapeMatches
                        ? chooseLocalRecovery(saved, {
                            scope: recoveryKey, run: data.run, setup: data.setup, answers: data.answers,
                        })
                        : 'none';
                    if (decision === 'safe' || decision === 'conflict') {
                        recoveryRecord.value = saved;
                        recoveryMode.value = decision;
                    } else if (saved && (decision === 'synced' || decision === 'expired')) {
                        await queueLocalWrite({ type: 'delete', scope: recoveryKey });
                    }
                } catch (ignored) {
                    localStorageAvailable.value = false;
                }
            } else {
                localStorageAvailable.value = false;
            }
        }
        stage.value = 'setup';
    } catch (error) {
        loadError.value = error.message || 'Не удалось загрузить контроль.';
    } finally {
        loading.value = false;
    }
}

async function choosePerson(id) {
    if (!canEdit.value) return;
    if (id === '__test__') {
        if (viewerRole.value !== 'admin') return;
        if (testMode.value) return;
        // Never discard unfinished real work when switching to a local-only
        // sandbox. Existing server drafts remain completely untouched.
        if (busyUploads.value || interruptedUploads.value.length) {
            syncError.value = 'Сначала завершите реальные загрузки перед переходом в тестовый режим.';
            return;
        }
        if (!(await ensureSynced())) {
            syncError.value = syncError.value || 'Сначала сохраните текущий черновик.';
            return;
        }
        cleanupPreviews();
        testMode.value = true;
        uploads.value = [];
        media.value = rooms.value.map(room => (room.items ?? []).map(() => []));
        answers.value = rooms.value.map(room => (room.items ?? []).map(() =>
            ({ selected: '', custom: '', corrective: { repeats: null, action: '', recheck: null } })));
        setup.value = { ...setup.value, cleaner_id: '__test__', apartment_id: null,
            previous_cleaner: '', is_assigned: false, comment: '' };
        state.value = 'synced';
        syncError.value = '';
        stage.value = 'setup';
        return;
    }
    if (testMode.value) return;
    setup.value.cleaner_id = id;
    touch();
}
function chooseApartment(id) {
    if (!canEdit.value) return;
    setup.value.apartment_id = id;
    touch();
}
function exitTestMode() {
    if (!testMode.value) return;
    testMode.value = false;
    finalResult.value = null;
    void initialize(); // Restore the real server-owned draft, never test values.
}
function resumeDraftFiles(event) {
    const selected = Array.from(event.target.files ?? []);
    event.target.value = '';
    mediaError.value = '';
    if (!canEdit.value || testMode.value) return;
    let restored = 0;
    for (const file of selected) {
        const record = interruptedUploads.value.find(item =>
            item.status === 'pending'
            && item.original_name === file.name && Number(item.size) === file.size
            && item.mime === focusFileMime(file)
            && !uploads.value.some(upload => upload.id === item.upload_id &&
                ['queued', 'uploading', 'attaching'].includes(upload.status)));
        if (!record) continue;
        const error = allowedFocusFile(file);
        if (error) { mediaError.value = error; continue; }
        const previous = uploads.value.find(upload => upload.id === record.upload_id);
        const data = {
            id: record.upload_id, file, record, room: record.room_index,
            question: record.question_index, status: 'queued', progress: 0,
            error: '', controller: null, info: null, preview_url: URL.createObjectURL(file),
        };
        if (previous) {
            if (previous.preview_url) URL.revokeObjectURL(previous.preview_url);
            Object.assign(previous, data);
        } else uploads.value.push(data);
        restored++;
    }
    if (restored) pumpTransfers();
    if (restored !== selected.length) {
        mediaError.value = 'Не все файлы совпали с прерванными загрузками. Выберите оригиналы с тем же названием, размером и форматом.';
    }
}
async function attachReadyDraft(record) {
    if (!canEdit.value || testMode.value || record.status !== 'ready') return;
    if (activeTransfers || attachInFlight) {
        mediaError.value = 'Подождите завершения текущих загрузок.';
        return;
    }
    mediaError.value = '';
    try {
        if (!(await ensureSynced())) throw new Error('Не удалось сохранить ответы.');
        attachInFlight = true;
        const info = await focusMediaRequest(record.status_url, 'GET', undefined, csrf);
        if (info.status !== 'ready' && info.status !== 'attached') {
            throw new Error('Файл ещё не готов. Выберите исходный файл для возобновления.');
        }
        const attached = await focusMediaRequest(info.urls.attach, 'POST', {
            draft_id: run.value.draft_id, expected_revision: run.value.revision,
        }, csrf);
        run.value = { ...run.value, ...attached.run };
        const files = media.value[record.room_index][record.question_index];
        if (!files.some(file => file.upload_id === attached.media_item.upload_id)) {
            files.push(attached.media_item);
        }
        interruptedUploads.value = interruptedUploads.value.filter(item => item.upload_id !== record.upload_id);
    } catch (error) {
        mediaError.value = error.message || 'Не удалось прикрепить готовый файл.';
    } finally {
        attachInFlight = false;
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
    // Corrective form is intentionally hidden in this release.
    answer.corrective = { repeats: null, action: '', recheck: null };
    touch();
}

function touch() {
    if (!canEdit.value) return;
    if (testMode.value) {
        state.value = 'synced';
        return; // Client-side test: never send any draft writes.
    }
    changeSerial++;
    state.value = 'dirty';
    syncError.value = '';
    saveLocalSnapshot();
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
    if (testMode.value) return true;
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
            settleLocalSnapshot();
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
    if (testMode.value) return true;
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
    submitError.value = '';
}


async function refreshMediaReview() {
    const url = finalResult.value?.media_status_url || props.reviewStatusUrl;
    if (!url) return;
    try {
        const latest = await fetchJson(url);
        finalResult.value = { ...finalResult.value, ...latest };
        submitError.value = '';
    } catch (error) {
        submitError.value = error.message || 'Не удалось обновить статус материалов.';
    }
}

async function registerRemainingEvidence() {
    // All selected files need a server reservation before sign-off. Without
    // it, a closed WebView would forget the evidence was ever selected.
    const waiting = uploads.value.filter(item => item.status !== 'cancelled');
    for (const item of waiting) {
        if (!item.file || allowedFocusFile(item.file)) {
            throw new Error('Некоторые файлы нельзя зарегистрировать. Уберите неподдерживаемые вложения перед завершением.');
        }
    }
    // Do not create hundreds of simultaneous requests against shared PHP
    // hosting. Each successful registration is idempotent on retry.
    const missing = waiting.filter(item => !item.info);
    for (let start = 0; start < missing.length; start += 4) {
        await Promise.all(missing.slice(start, start + 4).map(async item => {
            item.info = await focusMediaRequest(props.mediaStartUrl, 'POST', {
                upload_id: item.id,
                control_id: control.value.id,
                draft_id: run.value.draft_id,
                run_id: run.value.id,
                schema_version: control.value.schema_version,
                room_index: item.room,
                question_index: item.question,
                original_name: item.file.name,
                mime: focusFileMime(item.file),
                size: item.file.size,
            }, csrf);
        }));
    }
}

async function attachReadyEvidence(record) {
    if (!finalResult.value || record.status !== 'ready') return;
    submitError.value = '';
    try {
        const current = await focusMediaRequest(record.status_url, 'GET', undefined, csrf);
        await focusMediaRequest(current.urls.attach, 'POST', {
            draft_id: record.draft_id, expected_revision: 0,
        }, csrf);
        await refreshMediaReview();
    } catch (error) {
        submitError.value = error.message || 'Файл пока не удалось прикрепить.';
    }
}

function enqueueDeferredFile(file, record) {
    if (!file || !finalResult.value || record.status !== 'pending') return false;
    if (file.name !== record.original_name || file.size !== record.size || focusFileMime(file) !== record.mime) {
        return false;
    }
    const existing = uploads.value.find(item => item.id === record.upload_id);
    if (existing && ['queued', 'uploading', 'attaching'].includes(existing.status)) {
        return false;
    }
    if (existing) {
        Object.assign(existing, { file, record, status: 'queued', error: '', progress: 0 });
    } else {
        uploads.value.push({
            id: record.upload_id,
            file, room: record.room_index, question: record.question_index,
            record, status: 'queued', progress: 0, error: '', controller: null, info: null,
        });
    }
    return true;
}

function resumeDeferredFile(event, record) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (enqueueDeferredFile(file, record)) {
        submitError.value = '';
        pumpTransfers();
    } else {
        submitError.value = 'Выберите исходный файл с тем же названием, размером и форматом.';
    }
}

function resumeDeferredFiles(event) {
    const files = Array.from(event.target.files ?? []);
    event.target.value = '';
    const pending = (finalResult.value?.media?.uploads ?? [])
        .filter(upload => upload.status === 'pending');
    let accepted = 0;
    for (const file of files) {
        const record = pending.find(upload =>
            upload.original_name === file.name && upload.size === file.size
            && upload.mime === focusFileMime(file)
            && !uploads.value.some(item => item.id === upload.upload_id
                && ['queued', 'uploading', 'attaching'].includes(item.status)));
        if (record && enqueueDeferredFile(file, record)) accepted++;
    }
    if (accepted > 0) pumpTransfers();
    submitError.value = files.length > accepted
        ? 'Не все выбранные файлы совпали с ожидаемыми. Проверьте названия, размер и формат материалов.'
        : '';
}


async function submitControl() {
    if (submitting.value || stage.value !== 'review' || !canEdit.value) return;
    const missing = firstMissing();
    if (missing) {
        goRoom(missing.roomIndex);
        blockedQuestion.value = missing.questionIndex;
        return;
    }
    submitting.value = true;
    submitError.value = '';
    try {
        if (testMode.value) {
            const count = media.value.flat().reduce((sum, files) => sum + files.length, 0);
            finalResult.value = {
                test_mode: true, media: { state: 'complete', total: count, attached: count,
                    pending: 0, ready_to_attach: 0, cancelled: 0, uploads: [] },
            };
            stage.value = 'done';
            return;
        }
        if (!canSubmitMedia.value) {
            throw new Error('Дождитесь загрузки всех фото и видео. Ошибочные файлы повторите или отмените. При восстановлении выберите исходные файлы.');
        }
        if (!(await ensureSynced())) {
            throw new Error(syncError.value || 'Сначала сохраните ответы.');
        }
        if (!run.value?.draft_id) {
            throw new Error('Черновик ещё не сохранён.');
        }
        await attachQueue;
        if (!canSubmitMedia.value) {
            throw new Error('Не все фото и видео подтверждены сервером.');
        }
        if (!(await ensureSynced())) {
            throw new Error(syncError.value || 'Не удалось синхронизировать ответы.');
        }
        const result = await fetchJson(props.submitUrl, {
            method: 'POST',
            body: JSON.stringify({
                control_id: control.value.id,
                run_id: run.value.id,
                schema_version: control.value.schema_version,
                draft_id: run.value.draft_id,
                expected_revision: run.value.revision,
            }),
        });
        if (!result.submitted || !result.result_url) {
            throw new Error('Сервер не подтвердил завершение проверки.');
        }
        finalResult.value = result;
        stage.value = 'done';
        state.value = 'synced';
        if (recoveryKey) void queueLocalWrite({ type: 'delete', scope: recoveryKey });
    } catch (error) {
        submitError.value = error.message || 'Не удалось завершить контроль. Повторите отправку.';
        if (error.status === 409 && error.code !== 'submission_in_progress') {
            state.value = 'conflict';
        }
    } finally {
        submitting.value = false;
    }
}

async function openClassic() {
    if (submitting.value || stage.value === 'done') return;
    if (recoveryMode.value) {
        syncError.value = 'Сначала выберите, какую версию черновика оставить.';
        return;
    }
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
    pickerFor.value = null;
    mediaError.value = '';
    if (!canEdit.value) return;

    const current = media.value[room]?.[question]?.length ?? 0;
    let reserved = pendingAt(room, question).length
        + interruptedUploads.value.filter(item => item.room_index === room && item.question_index === question).length;
    for (const file of selected) {
        const error = allowedFocusFile(file);
        if (current + reserved >= 12) {
            uploads.value.push({ id: crypto.randomUUID(), file, room, question, status: 'error',
                progress: 0, error: 'Не больше 12 вложений на один вопрос.' });
            continue;
        }
        const id = crypto.randomUUID();
        if (testMode.value && !error) {
            const files = media.value[room][question] ?? (media.value[room][question] = []);
            const url = URL.createObjectURL(file);
            files.push({ upload_id: id, name: file.name, mime: focusFileMime(file),
                size: file.size, url, thumbnail_url: url, local: true });
        } else {
            uploads.value.push({
                id, file, room, question, status: error ? 'error' : 'queued',
                progress: 0, error, controller: null, info: null,
                preview_url: !error ? URL.createObjectURL(file) : null,
            });
        }
        if (!error) reserved++;
    }
    if (!testMode.value) pumpTransfers();
}

function pumpTransfers() {
    while (activeTransfers < 2 && (canEdit.value || stage.value === 'done')) {
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
        // Before completion, the draft must be saved. Afterwards, transfers
        // resume against their original registered upload ID and result.
        if (!finalResult.value) {
            if (!run.value?.draft_id) touch();
            if (!(await ensureSynced())) throw new Error('Сначала сохраните ответы. Затем повторите загрузку.');
        }
        const context = item.record
            ? {
                control_id: item.record.control_id,
                draft_id: item.record.draft_id,
                run_id: item.record.run_id,
                schema_version: item.record.schema_version,
            }
            : {
                control_id: control.value.id,
                draft_id: run.value.draft_id,
                run_id: run.value.id,
                schema_version: control.value.schema_version,
            };

        const result = await transferFocusFile({
            startUrl: props.mediaStartUrl,
            file: item.file,
            uploadId: item.id,
            draft: context,
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
            if (!finalResult.value && !(await ensureSynced())) {
                throw new Error('Ответы не сохранены. Повторите прикрепление.');
            }
            attachInFlight = true;
            try {
                const attached = await focusMediaRequest(result.urls.attach, 'POST', {
                    draft_id: context.draft_id,
                    expected_revision: finalResult.value ? 0 : run.value.revision,
                }, csrf, controller.signal);
                if (finalResult.value) {
                    await refreshMediaReview();
                } else {
                    run.value = { ...run.value, ...attached.run };
                    if (changeSerial !== acknowledgedSerial) saveLocalSnapshot();
                    const arr = media.value[item.room] ?? (media.value[item.room] = []);
                    const files = arr[item.question] ?? (arr[item.question] = []);
                    if (!files.some(file => file.upload_id === attached.media_item.upload_id)) {
                        files.push(attached.media_item);
                    }
                }
            } finally {
                attachInFlight = false;
                if (!finalResult.value && changeSerial !== acknowledgedSerial) queueSave(0);
            }
        });
        attachQueue = task.catch(() => {});
        await task;
        interruptedUploads.value = interruptedUploads.value.filter(other => other.upload_id !== item.id);
        if (item.preview_url) URL.revokeObjectURL(item.preview_url);
        uploads.value = uploads.value.filter(other => other.id !== item.id);
    } catch (error) {
        if (controller.signal.aborted) {
            // Cancellation may still be awaiting its own server confirmation.
            if (item.status !== 'error') item.status = 'cancelled';
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
    if (!canRetryFocusFile(item, canEdit.value, finalResult.value?.response_id)) return;
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
    if (item.preview_url) {
        URL.revokeObjectURL(item.preview_url);
        item.preview_url = null;
    }
    if (item.info?.urls?.cancel) {
        try {
            await focusMediaRequest(item.info.urls.cancel, 'DELETE', undefined, csrf);
        } catch (error) {
            item.status = 'error';
            item.error = error.status === 409
                ? 'Статус файла изменился на сервере. Проверьте вложение и повторите действие.'
                : 'Сервер не подтвердил отмену файла. Повторите «Отменить», когда соединение восстановится.';
        }
    }
}

function dismissFile(item) {
    if (['queued', 'uploading', 'attaching'].includes(item.status)) return;
    if (item.preview_url) URL.revokeObjectURL(item.preview_url);
    uploads.value = uploads.value.filter(other => other.id !== item.id);
}
function cleanupPreviews() {
    uploads.value.forEach(item => { if (item.preview_url) URL.revokeObjectURL(item.preview_url); });
    media.value.flat().flat().forEach(file => {
        if (file.local && file.url) URL.revokeObjectURL(file.url);
    });
}
function removeTestMedia(room, question, file) {
    if (!testMode.value) return;
    if (file.local && file.url) URL.revokeObjectURL(file.url);
    media.value[room][question] = media.value[room][question].filter(item => item !== file);
}
function pickerKey(room, question) { return room + '-' + question; }
function togglePicker(room, question) {
    const key = pickerKey(room, question);
    pickerFor.value = pickerFor.value === key ? null : key;
}
function mediaIsVideo(file) { return String(file.mime || focusFileMime(file.file || {})).startsWith('video/'); }
function mediaPreview(file) { return file.preview_url || file.thumbnail_url || file.url || ''; }

function warnBeforeUnload(event) {
    if (submitting.value || busyUploads.value || state.value === 'dirty' || state.value === 'saving' || state.value === 'error') {
        event.preventDefault();
        event.returnValue = '';
    }
}

onMounted(() => {
    window.addEventListener('beforeunload', warnBeforeUnload);
    void initialize();
});
onBeforeUnmount(() => {
    cleanupPreviews();
    clearTimeout(timer);
    window.removeEventListener('beforeunload', warnBeforeUnload);
});
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <div class="mx-auto min-h-screen max-w-2xl px-4 pb-24 pt-4 sm:px-6">
            <header class="mb-4 flex items-center justify-between gap-3 border-b border-slate-200 pb-4">
                <div>
                    <p class="text-xs font-medium text-slate-500">TRIS Academy · Тестовая версия</p>
                    <h1 class="mt-1 text-xl font-semibold tracking-tight">Контроль качества</h1>
                </div>
                <a v-if="stage !== 'done'" :href="classicUrl" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700" @click.prevent="openClassic">Старый Focus</a>
                <a v-else-if="finalResult?.result_url" :href="finalResult.result_url" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700">Результат</a>
            </header>

            <div v-if="loading" role="status" class="rounded-xl bg-white p-7 text-center text-sm">Загружаем вопросы…</div>
            <div v-else-if="loadError" role="alert" class="rounded-xl bg-white p-5">
                <p class="font-bold text-red-700">{{ loadError }}</p>
                <button type="button" class="mt-3 rounded-xl bg-blue-600 px-4 py-2 font-semibold text-white" @click="initialize">Повторить</button>
            </div>

            <template v-else>
                <div class="mb-3 flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <div class="flex items-center gap-2 text-sm" role="status" aria-live="polite">
                        <span class="size-2.5 rounded-full" :class="state === 'synced' || state === 'idle' ? 'bg-emerald-500' : state === 'saving' ? 'bg-blue-500' : state === 'dirty' ? 'bg-amber-500' : 'bg-red-500'"></span>
                        <strong>{{ statusLabel }}</strong>
                    </div>
                    <button type="button" :disabled="!canEdit || state === 'saving'" class="rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 disabled:opacity-50" @click="flush">
                        {{ state === 'error' ? 'Повторить' : 'Сохранить' }}
                    </button>
                </div>

                <div v-if="!localStorageAvailable && stage !== 'done'" role="status" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                    Локальное восстановление недоступно в этом браузере. Дождитесь статуса «Сохранено» перед закрытием Telegram.
                </div>
                <section v-if="recoveryMode && recoveryRecord" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="alert">
                    <h2 class="font-bold">Найдена локальная копия ответов</h2>
                    <p v-if="recoveryMode === 'safe'" class="mt-2">
                        Эти изменения могли не успеть сохраниться на сервере. Выберите, какую версию продолжить.
                    </p>
                    <p v-else class="mt-2">
                        Серверный черновик изменился после создания локальной копии либо принадлежит другому запуску.
                        Чтобы не перезаписать более свежие ответы, автоматическое восстановление заблокировано.
                        Можно просмотреть локальную копию и перенести нужные ответы вручную.
                    </p>
                    <details v-if="recoveryMode === 'conflict'" class="mt-3 rounded-lg border border-amber-200 bg-white p-3">
                        <summary class="cursor-pointer font-semibold">Показать локальные ответы для копирования</summary>
                        <textarea readonly :value="JSON.stringify({ setup: recoveryRecord.setup, answers: recoveryRecord.answers }, null, 2)"
                            rows="8" class="mt-2 w-full rounded-lg border border-slate-200 p-2 font-mono text-xs"></textarea>
                    </details>
                    <p v-if="recoveryError" role="alert" class="mt-2 font-semibold text-red-700">{{ recoveryError }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button v-if="recoveryMode === 'safe'" type="button" :disabled="recoveryBusy"
                            class="rounded-xl bg-blue-600 px-4 py-3 font-bold text-white disabled:opacity-50"
                            @click="restoreLocalVersion">Восстановить локальные ответы</button>
                        <button type="button" :disabled="recoveryBusy" class="rounded-xl border border-amber-300 bg-white px-4 py-3 font-bold disabled:opacity-50"
                            @click="keepServerVersion">Оставить серверную версию</button>
                    </div>
                </section>
                <div v-if="uploads.length && stage !== 'done'" class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 text-xs" role="status" aria-live="polite">
                    <div class="min-w-0">
                        <strong class="text-slate-700">Фото и видео</strong>
                        <span class="ml-2 text-slate-500">В очереди: {{ uploads.filter(item => ['queued', 'uploading', 'attaching'].includes(item.status)).length }}</span>
                        <span v-if="uploads.some(item => item.status === 'error')" class="ml-2 font-semibold text-red-700">Ошибки: {{ uploads.filter(item => item.status === 'error').length }}</span>
                    </div>
                    <span class="shrink-0 text-slate-500">Файлы загружаются отдельно</span>
                </div>

                <div v-if="syncError" role="alert" class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    {{ syncError }}
                    <p v-if="state === 'conflict'" class="mt-2 font-semibold">Не перезагружайте страницу, если есть несохранённые ответы. Для восстановления сначала разберите конфликт черновика.</p>
                </div>

                <template v-if="stage === 'setup'">
                    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
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
                        <button type="button" :disabled="!canEdit" class="w-full rounded-xl bg-blue-600 p-4 text-base font-bold text-white disabled:opacity-50" @click="goRoom(0)">
                            К вопросам
                        </button>
                    </section>
                </template>

                <template v-else-if="stage === 'questions'">
                    <section class="mb-4 rounded-xl border border-slate-200 bg-white p-4">
                        <div class="mb-2 flex items-center justify-between gap-2">
                            <strong class="text-sm">Заполнено {{ completedRequired }} из {{ totalRequired }}</strong>
                            <span class="text-xs text-slate-500">{{ rooms.length }} комнат</span>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" :aria-valuenow="completedRequired" :aria-valuemax="totalRequired" aria-valuemin="0">
                            <div class="h-full rounded-full bg-blue-600 transition-all" :style="{ width: (totalRequired ? completedRequired / totalRequired * 100 : 100) + '%' }"></div>
                        </div>
                        <nav class="mt-4 flex gap-2 overflow-x-auto pb-1" aria-label="Комнаты">
                            <button v-for="(room, index) in rooms" :key="index" type="button" class="shrink-0 rounded-xl px-3 py-2 text-xs font-semibold" :class="activeRoom === index ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-600'" :aria-current="activeRoom === index ? 'step' : undefined" @click="goRoom(index)">
                                {{ room.title || 'Комната ' + (index + 1) }} · {{ roomCompleted(index) }}/{{ roomRequired(index) }}
                            </button>
                        </nav>
                    </section>

                    <div class="mb-4 flex items-start justify-between gap-2 px-1">
                        <h2 class="text-xl font-semibold">{{ currentRoom.title }}</h2>
                        <span class="whitespace-nowrap text-xs font-semibold text-slate-500">{{ activeRoom + 1 }}/{{ rooms.length }}</span>
                    </div>

                    <div class="space-y-3">
                        <article v-for="(question, questionIndex) in currentRoom.items" :key="activeRoom + '-' + questionIndex" class="rounded-xl border bg-white p-4" :class="blockedQuestion === questionIndex ? 'border-red-400' : 'border-slate-200'">
                            <div class="flex justify-between gap-3">
                                <h3 class="text-base font-bold leading-snug">{{ question.question || 'Вопрос' }}</h3>
                                <span v-if="currentRoom.is_optional || question.is_optional" class="shrink-0 text-xs text-slate-500">Необязательно</span>
                            </div>
                            <p v-if="blockedQuestion === questionIndex" class="mt-2 text-xs font-semibold text-red-600">Нужно ответить на этот вопрос.</p>
                            <img v-if="question.question_image_url" :src="question.question_image_url" alt="" class="mt-3 max-h-48 rounded-xl object-contain">

                            <div v-if="['options', 'both'].includes(question.answer_type ?? 'options')" class="mt-4 grid gap-2">
                                <button v-for="(option, optionIndex) in question.answer_options_scored ?? []" :key="optionIndex" type="button" :disabled="!canEdit" :aria-pressed="answers[activeRoom][questionIndex].selected === optionValue(option, optionIndex)"
                                    class="min-h-11 rounded-lg border px-3 py-3 text-left text-sm font-medium disabled:opacity-50"
                                    :class="answers[activeRoom][questionIndex].selected === optionValue(option, optionIndex) ? 'border-blue-600 bg-blue-50 text-blue-800' : 'border-slate-200 bg-white text-slate-700'"
                                    @click="changeAnswer(activeRoom, questionIndex, optionValue(option, optionIndex))">
                                    <span class="mr-2 inline-block w-4 text-center" aria-hidden="true">{{ answers[activeRoom][questionIndex].selected === optionValue(option, optionIndex) ? '●' : '○' }}</span>
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
                                    <label class="cursor-pointer rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700" :class="!canEdit ? 'pointer-events-none opacity-50' : ''">
                                        + Добавить
                                        <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm" class="sr-only" :disabled="!canEdit" @change="addFiles($event, activeRoom, questionIndex)">
                                    </label>
                                </div>
                                <div v-if="(media[activeRoom]?.[questionIndex] ?? []).length" class="mt-2 flex flex-wrap gap-2">
                                    <a v-for="file in media[activeRoom][questionIndex]" :key="file.upload_id || file.index" :href="file.url" target="_blank" rel="noopener noreferrer" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-blue-700">{{ file.name || 'Вложение' }}</a>
                                </div>
                                <div v-for="item in uploads.filter(file => file.room === activeRoom && file.question === questionIndex)" :key="item.id" class="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="min-w-0 truncate font-semibold">{{ item.file?.name }}</span>
                                        <span class="shrink-0 text-slate-500">{{ item.status === 'queued' ? 'В очереди' : item.status === 'uploading' ? 'Загрузка' : item.status === 'attaching' ? 'Прикрепляем' : item.status === 'cancelled' ? 'Отменено' : 'Ошибка' }}</span>
                                    </div>
                                    <div v-if="['uploading', 'attaching'].includes(item.status)" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-200">
                                        <div class="h-full bg-blue-600" :style="{ width: item.progress + '%' }"></div>
                                    </div>
                                    <p v-if="item.error" role="alert" class="mt-2 text-red-700">{{ item.error }}</p>
                                    <div class="mt-2 flex gap-2">
                                        <button v-if="item.status === 'error'" type="button" class="rounded-lg bg-blue-600 px-3 py-1.5 font-semibold text-white" @click="retryFile(item)">Повторить</button>
                                        <button v-if="['queued', 'uploading', 'error'].includes(item.status)" type="button" class="rounded-lg bg-white px-3 py-1.5 font-semibold text-slate-700" @click="cancelFile(item)">Отменить</button>
                                        <button v-else type="button" class="rounded-lg bg-white px-3 py-1.5 font-semibold text-slate-700" @click="dismissFile(item)">Скрыть</button>
                                    </div>
                                </div>
                            </div>
                        </article>
                    </div>

                    <div class="sticky bottom-3 z-10 mt-4 grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
                        <button type="button" class="rounded-xl bg-slate-100 p-3 text-sm font-bold text-slate-700" @click="activeRoom === 0 ? stage = 'setup' : goRoom(activeRoom - 1)">Назад</button>
                        <button type="button" class="rounded-xl bg-blue-600 p-3 text-sm font-bold text-white" @click="nextRoom">
                            {{ activeRoom === rooms.length - 1 ? 'К сводке' : 'Далее' }}
                        </button>
                    </div>
                </template>

                <template v-else-if="stage === 'review'">
                    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Проверка ответов</p>
                        <h2 class="text-xl font-semibold">Все вопросы заполнены</h2>
                        <p class="text-sm text-slate-600">{{ completedRequired }} из {{ totalRequired }} обязательных вопросов. Сохраните ответы перед завершением.</p>
                        <div v-for="(room, index) in rooms" :key="index" class="flex items-center justify-between border-b border-slate-100 py-2">
                            <span class="text-sm font-semibold">{{ room.title }}</span>
                            <span class="text-sm font-bold text-blue-700">{{ roomCompleted(index) }}/{{ roomRequired(index) }}</span>
                        </div>
                        <div v-if="busyUploads" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900" role="status">
                            Фото и видео ещё загружаются. Вы можете завершить проверку сейчас: мы зарегистрируем оставшиеся файлы на сервере, а материалы догрузятся отдельно. Если Telegram закроется, понадобится снова выбрать исходные файлы.
                        </div>
                        <div v-if="submitError" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                            {{ submitError }}
                        </div>
                        <button type="button" :disabled="!canEdit || submitting" class="w-full rounded-xl bg-blue-600 p-4 text-sm font-bold text-white disabled:opacity-50" @click="submitControl">
                            {{ submitting ? 'Отправляем контроль…' : 'Отправить контроль' }}
                        </button>
                        <button type="button" :disabled="submitting || busyUploads" class="w-full rounded-xl bg-blue-50 p-3 text-sm font-bold text-blue-700 disabled:opacity-50" @click="openClassic">
                            Продолжить в старом Focus
                        </button>
                        <button type="button" :disabled="submitting" class="w-full rounded-xl bg-slate-100 p-3 text-sm font-bold disabled:opacity-50" @click="goRoom(rooms.length - 1)">Вернуться к вопросам</button>
                    </section>
                </template>
                <template v-else-if="stage === 'done'">
                    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5" aria-live="polite">
                        <div class="text-3xl" aria-hidden="true">✓</div>
                        <h2 class="text-xl font-semibold text-emerald-800">Контроль завершён</h2>
                        <p class="text-sm text-slate-600">Ответы и оценка сохранены. Готовность фото и видео отслеживается отдельно.</p>
                        <div class="rounded-xl bg-slate-50 p-4">
                            <p class="text-sm text-slate-600">Оценка уборки</p>
                            <p class="mt-1 text-2xl font-semibold">{{ finalResult?.total_points }} / {{ finalResult?.max_points }} · {{ finalResult?.score_percent }}%</p>
                            <p class="mt-2 text-sm font-bold" :class="finalResult?.result_zone === 'green' ? 'text-emerald-700' : finalResult?.result_zone === 'red' ? 'text-red-700' : 'text-amber-700'">
                                {{ finalResult?.result_zone === 'green' ? 'Зелёная зона' : finalResult?.result_zone === 'red' ? 'Красная зона' : 'Жёлтая зона' }}
                            </p>
                            <p v-if="finalResult?.result_zone_reason" class="mt-1 text-xs text-slate-500">{{ finalResult.result_zone_reason }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-base font-bold">Фото и видео</h3>
                                <button type="button" class="rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700" @click="refreshMediaReview">Обновить</button>
                            </div>
                            <p v-if="finalResult?.media?.state === 'complete'" class="mt-2 text-sm font-bold text-emerald-700">✓ Все зарегистрированные материалы получены.</p>
                            <p v-else-if="finalResult?.media?.state === 'awaiting_media'" class="mt-2 text-sm font-bold text-amber-800">⏳ Контроль завершён · Ожидаются материалы.</p>
                            <p v-else-if="finalResult?.media?.state === 'needs_review'" class="mt-2 text-sm font-bold text-red-700">⚠ Материалы требуют проверки: есть отменённые загрузки.</p>
                            <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                                <div class="rounded-xl bg-emerald-50 p-2"><strong class="block text-lg">{{ finalResult?.media?.attached ?? 0 }}</strong>Прикреплено</div>
                                <div class="rounded-xl bg-amber-50 p-2"><strong class="block text-lg">{{ (finalResult?.media?.pending ?? 0) + (finalResult?.media?.ready_to_attach ?? 0) }}</strong>Ожидается</div>
                                <div class="rounded-xl bg-red-50 p-2"><strong class="block text-lg">{{ finalResult?.media?.cancelled ?? 0 }}</strong>Отменено</div>
                            </div>
                            <p v-if="!finalResult?.media?.total" class="mt-3 text-xs text-slate-500">Новых загрузок нет. Вложения из черновика сохранены вместе с результатом.</p>
                            <label v-if="(finalResult?.media?.pending ?? 0) > 0" class="mt-3 inline-flex cursor-pointer rounded-xl bg-blue-50 px-4 py-3 text-xs font-bold text-blue-700">
                                Возобновить сразу несколько фото и видео
                                <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm" class="sr-only" @change="resumeDeferredFiles">
                            </label>
                            <div v-for="record in finalResult?.media?.uploads ?? []" :key="record.upload_id" class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate font-bold">{{ record.original_name }}</p>
                                        <p class="text-slate-500">{{ record.mime.startsWith('video/') ? 'Видео' : 'Фото' }} · {{ Math.ceil(record.size / 1024) }} КБ · Комната {{ record.room_index + 1 }}, вопрос {{ record.question_index + 1 }}</p>
                                    </div>
                                    <span class="shrink-0 font-semibold" :class="record.status === 'attached' ? 'text-emerald-700' : record.status === 'cancelled' ? 'text-red-700' : 'text-amber-800'">
                                        {{ record.status === 'attached' ? 'Сохранено' : record.status === 'cancelled' ? 'Не получено' : record.status === 'ready' ? 'Нужно прикрепить' : 'Ожидает загрузки' }}
                                    </span>
                                </div>
                                <div v-if="uploads.some(item => item.id === record.upload_id && ['queued','uploading','attaching'].includes(item.status))" class="mt-2 text-blue-700">
                                    Загружается · {{ uploads.find(item => item.id === record.upload_id)?.progress ?? 0 }}%
                                </div>
                                <div v-if="uploads.some(item => item.id === record.upload_id && item.status === 'error')" class="mt-2 space-y-2 text-red-700" role="alert">
                                    <p>{{ uploads.find(item => item.id === record.upload_id)?.error }}</p>
                                    <button type="button"
                                        class="rounded-lg bg-blue-600 px-3 py-2 font-bold text-white"
                                        @click="retryFile(uploads.find(item => item.id === record.upload_id))">
                                        Повторить загрузку
                                    </button>
                                </div>
                                <div v-if="record.status === 'pending'" class="mt-2">
                                    <label class="inline-flex cursor-pointer rounded-lg bg-blue-50 px-3 py-2 font-bold text-blue-700">
                                        Выбрать исходный файл и продолжить
                                        <input type="file" class="sr-only" :accept="record.mime" @change="resumeDeferredFile($event, record)">
                                    </label>
                                </div>
                                <button v-if="record.status === 'ready'" type="button" class="mt-2 rounded-lg bg-blue-50 px-3 py-2 font-bold text-blue-700" @click="attachReadyEvidence(record)">
                                    Прикрепить готовый файл
                                </button>
                            </div>
                            <p v-if="finalResult?.media?.state !== 'complete'" class="mt-4 rounded-xl bg-amber-50 p-3 text-xs text-amber-900">
                                Передача продолжается, пока открыта эта страница. После закрытия Telegram откройте проверку материалов снова и выберите те же фото/видео: ранее полученные сервером части будут пропущены. Исходные файлы остаются на вашем устройстве.
                            </p>
                            <div v-if="submitError" role="alert" class="mt-3 rounded-xl bg-red-50 p-3 text-xs text-red-700">{{ submitError }}</div>
                        </div>
                        <a v-if="finalResult?.media_review_url" :href="finalResult.media_review_url" class="block rounded-xl bg-blue-50 p-3 text-center text-sm font-bold text-blue-700">Открыть проверку материалов позже</a>
                        <a v-if="finalResult?.result_url" :href="finalResult.result_url" class="block w-full rounded-xl bg-blue-600 p-4 text-center text-sm font-bold text-white">Открыть результат</a>
                        <a href="/checks" class="block w-full rounded-xl bg-slate-100 p-3 text-center text-sm font-bold text-slate-700">Все контроли</a>
                    </section>
                </template>
            </template>
        </div>
    </div>
</template>
