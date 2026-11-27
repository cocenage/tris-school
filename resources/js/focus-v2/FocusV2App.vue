<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import FocusCamera from './FocusCamera.vue';
import FocusSearchSelect from './FocusSearchSelect.vue';
import FocusMediaViewer from './FocusMediaViewer.vue';
import FocusVideoThumb from './FocusVideoThumb.vue';
import { allowedFocusFile, attachFocusFile, canRetryFocusFile, focusFileMime, focusMediaRequest, isTransientFocusUploadError, transferFocusFile } from './media-upload';
import { cacheFocusPhoto, canCacheFocusMedia, chooseLocalRecovery, createLocalRecoveryQueue, deleteLocalRecovery, forgetCachedFocusPhoto, readCachedFocusPhotos, readLocalRecovery, recoveryScope, writeLocalRecovery } from './local-recovery';

const props = defineProps({
    bootstrapUrl: { type: String, required: true },
    draftUrl: { type: String, required: true },
    resetUrl: { type: String, default: '' },
    submitUrl: { type: String, required: true },
    reviewStatusUrl: { type: String, default: '' },
    mediaStartUrl: { type: String, required: true },
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
const resumePosition = ref(null);
const blockedQuestion = ref(null);
const highlightedReviewQuestion = ref(null);
const submitting = ref(false);
const resetting = ref(false);
const submitError = ref('');
const setupIssue = ref(null);
const editingReviewSetup = ref(false);
const finalResult = ref(null);
const uploads = ref([]);
const interruptedUploads = ref([]);
const viewerRole = ref('');
const viewerId = ref(null);
const testMode = ref(false);
const pickerFor = ref(null);
const camera = ref(null);
const roomPickerShown = ref(false);
const roomPicker = ref(null);
const roomPickerTrigger = ref(null);
const recoveryAction = ref(null);
const recoveryFileError = ref(null);
const mediaError = ref('');
const photoCapture = ref(null);
const batchPreview = ref(null);
const batchPreviewActive = computed(() => batchPreview.value?.items?.[batchPreview.value.index] ?? null);
const videoLightbox = ref(null);
const lightbox = ref(null);
const galleryViewer = ref(null);
const cacheWarning = ref('');
const recoveringPhotos = ref(false);
const recoveryRecord = ref(null);
const recoveryMode = ref('');
const recoveryBusy = ref(false);
const recoveryError = ref('');
const localStorageAvailable = ref(true);
const connectionOnline = ref(navigator.onLine !== false);
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
    if (!recoveryKey) return;
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
        void restoreDraftMedia();
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
    readResumePosition();
    recoveryRecord.value = null;
    recoveryMode.value = '';
    touch();
    void restoreDraftMedia();
}
const busyUploads = computed(() => uploads.value.some(item => ['queued', 'uploading', 'attaching', 'error'].includes(item.status)));
let activeTransfers = 0;
let recoveringDraftMedia = false;
let attachInFlight = null;
let attachQueue = Promise.resolve();

let changeSerial = 0;
let acknowledgedSerial = 0;
let timer = null;
let inFlight = null;
let uncertainSnapshot = null;
let pendingAttachReplay = null;

const statusLabel = computed(() => testMode.value && state.value === 'synced' ? 'Тестовый черновик сохранён' : ({
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
const canEdit = computed(() => Boolean(run.value && !resetting.value && !run.value.schema_conflict && state.value !== 'conflict' && !recoveryMode.value));
const peopleOptions = computed(() => viewerRole.value === 'admin'
    ? [{ id: '__test__', name: 'Тестовый сотрудник · без статистики', thumbnail: null }, ...people.value]
    : people.value);
// The previous cleaner is stored as a *name* in existing Control responses.
// Never send a user ID in this legacy field. Preserve names from older
// drafts even if the staff member is no longer active.
const previousPeopleOptions = computed(() => {
    const options = [{ id: '', name: 'Не указан', thumbnail: null },
        ...people.value.map(person => ({ ...person, id: person.name }))];
    const oldName = String(setup.value.previous_cleaner || '').trim();
    if (oldName && !options.some(person => person.id === oldName)) {
        options.push({ id: oldName, name: oldName + ' · из сохранённого контроля', thumbnail: null });
    }
    return options;
});
const outstandingMedia = computed(() => new Set([
    ...interruptedUploads.value.map(item => item.upload_id),
    ...uploads.value.filter(item => ['queued', 'uploading', 'attaching', 'error'].includes(item.status))
        .map(item => item.id),
]).size);
const canSubmitMedia = computed(() => outstandingMedia.value === 0);
const currentMediaTransfers = computed(() => uploads.value.filter(item =>
    ['queued', 'uploading', 'attaching'].includes(item.status)));
const failedMediaTransfers = computed(() => uploads.value.filter(item => item.status === 'error'));
const recoveryNeedingInput = computed(() => interruptedUploads.value.filter(record =>
    !uploads.value.some(item => item.id === record.upload_id
        && ['queued', 'uploading', 'attaching'].includes(item.status))
    && !(media.value[record.room_index]?.[record.question_index] ?? [])
        .some(file => file.upload_id === record.upload_id)));

const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function fetchJson(url, options = {}) {
    // Draft requests may succeed even when Telegram loses their response.
    // A bounded timeout lets the existing same-revision replay protocol
    // recover rather than leaving the UI stuck on “Сохраняем…” forever.
    const controller = new AbortController();
    const abort = () => controller.abort();
    options.signal?.addEventListener('abort', abort, { once: true });
    let timeoutFired = false;
    const timeout = setTimeout(() => { timeoutFired = true; controller.abort(); }, 25_000);
    let response, data;
    try {
        response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                ...(options.body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf } : {}),
            },
            ...options,
            signal: controller.signal,
        });
        data = await response.json().catch(error => {
            if (error?.name === 'AbortError' || error instanceof TypeError) throw error;
            return {};
        });
    } catch (error) {
        if (timeoutFired) throw new Error('Соединение зависло. При восстановлении повторим сохранение без потери ответов.');
        throw error;
    } finally {
        clearTimeout(timeout);
        options.signal?.removeEventListener('abort', abort);
    }
    if (!response.ok) {
        const firstError = Object.values(data.errors ?? {}).flat().find(message => typeof message === 'string');
        const exception = new Error(firstError || data.message || (response.status === 419
            ? 'Сессия истекла. Войдите снова.' : 'Сервер не ответил.'));
        exception.status = response.status;
        exception.code = data.error;
        exception.fields = data.errors;
        throw exception;
    }
    return data;
}

async function initialize() {
    // The same component can switch between real and test runs.
    // Never retain draft revisions, recovery prompts or old-run timers.
    clearTimeout(timer);
    timer = null;
    changeSerial = 0;
    acknowledgedSerial = 0;
    uncertainSnapshot = null;
    pendingAttachReplay = null;
    recoveryRecord.value = null;
    recoveryMode.value = '';
    recoveryKey = null;
    resumePosition.value = null;
    setupIssue.value = null;
    editingReviewSetup.value = false;
    loading.value = true;
    loadError.value = '';
    testMode.value = false;
    interruptedUploads.value = [];
    pickerFor.value = null;
    camera.value = null;
    roomPickerShown.value = false;
    recoveryFileError.value = null;
    mediaError.value = '';
    cacheWarning.value = '';
    closePhotoCapture();
    closeBatchPreview();
    closeLightbox();
    closeVideoLightbox();
    galleryViewer.value = null;
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
        viewerId.value = data.viewer?.id ?? null;
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
            readResumePosition();
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
        if (recoveryKey && !recoveryMode.value && !data.run?.schema_conflict) {
            await restoreDraftMedia();
        }
    } catch (error) {
        loadError.value = error.message || 'Не удалось загрузить контроль.';
    } finally {
        loading.value = false;
    }
}

async function restoreDraftMedia() {
    if (!canEdit.value || recoveringDraftMedia) return;
    recoveringDraftMedia = true;
    try {
        // Ready files are already on the server and may have no local Blob.
        // Finalize them first, before cached transfers start competing for revisions.
        await autoAttachReadyDraftFiles();
        if (canEdit.value) await restoreCachedPhotos();
    } finally {
        recoveringDraftMedia = false;
    }
}

async function restoreCachedPhotos() {
    if (!recoveryKey || recoveryMode.value || !run.value || recoveringPhotos.value) return;
    recoveringPhotos.value = true;
    try {
        const cached = await readCachedFocusPhotos(recoveryKey);
        // Bootstrap issues a new provisional ID when no server draft exists.
        // Only a single locally started run can claim an empty server slot.
        // Never move files into an existing or ambiguous run.
        if (run.value.draft_id == null && run.value.revision === 0
            && !cached.some(saved => saved.run_id === run.value.id)) {
            const localRuns = new Set(cached.filter(saved => saved.before_draft === true
                && rooms.value[saved.room]?.items?.[saved.question]).map(saved => saved.run_id));
            if (localRuns.size === 1) {
                run.value = { ...run.value, id: [...localRuns][0] };
                readResumePosition();
            }
        }
        for (const saved of cached) {
            // Never attach a cached file to a different draft/run.
            if (saved.run_id !== run.value.id) {
                cacheWarning.value = 'На устройстве остались материалы другой проверки. Они не будут загружены в этот контроль.';
                continue;
            }
            if (!rooms.value[saved.room]?.items?.[saved.question]) continue;
            if ((media.value[saved.room]?.[saved.question] ?? []).some(file => file.upload_id === saved.upload_id)) {
                await forgetCachedFocusPhoto(recoveryKey, saved.upload_id);
                continue;
            }
            if (uploads.value.some(item => item.id === saved.upload_id)) continue;
            const record = interruptedUploads.value.find(item => item.upload_id === saved.upload_id);
            const file = new File([saved.blob], saved.name, { type: saved.mime });
            if (allowedFocusFile(file)) continue;
            uploads.value.push({
                id: saved.upload_id, file, room: saved.room, question: saved.question,
                record: record || null, status: 'queued', progress: 0, error: '',
                info: null, controller: null, preview_url: URL.createObjectURL(file),
                cached: true,
                reservationAttempted: !!record || run.value.draft_id != null,
            });
        }
        pumpTransfers();
    } catch (error) {
        cacheWarning.value = 'Не удалось прочитать локальные фото или видео. Выберите исходные файлы повторно.';
    } finally {
        recoveringPhotos.value = false;
    }
}

function openCamera(room, question, kind) {
    if (!canEdit.value || mediaCountAt(room, question) >= 12) return;
    pickerFor.value = null;
    camera.value = { room, question, kind };
}
function onCameraCapture(file) {
    const target = camera.value;
    camera.value = null;
    if (target) beginCapture(file, target.room, target.question, target.kind);
}
function onCameraGallery(files) {
    const target = camera.value;
    camera.value = null;
    if (target && files.length) beginBatchPreview(files, target.room, target.question);
}
function beginCapture(file, room, question, kind) {
    pickerFor.value = null;
    if (!file || !canEdit.value) return;
    mediaError.value = '';
    const error = allowedFocusFile(file);
    if (error || !focusFileMime(file).startsWith(kind + '/')) {
        mediaError.value = error || (kind === 'image' ? 'Нужна фотография.' : 'Нужно видео MP4, MOV или WebM.');
        return;
    }
    closePhotoCapture();
    photoCapture.value = {
        file, room, question, kind, preview_url: URL.createObjectURL(file), saving: false,
    };
}
function closePhotoCapture() {
    if (photoCapture.value?.preview_url) URL.revokeObjectURL(photoCapture.value.preview_url);
    photoCapture.value = null;
}
function retakePhoto() {
    const current = photoCapture.value;
    if (!current || current.saving) return;
    closePhotoCapture();
    if (current.replaceUploadId) {
        document.getElementById('focus-replace-' + current.replaceUploadId)?.click();
    }
    else openCamera(current.room, current.question, current.kind);
}
async function confirmPhoto() {
    const current = photoCapture.value;
    if (!current || current.saving) return;
    current.saving = true;
    try {
        const queued = await queueSelectedFiles([current.file], current.room, current.question,
            { replaceUploadId: current.replaceUploadId });
        if (queued) closePhotoCapture();
    } catch (error) {
        mediaError.value = error.message || 'Не удалось добавить файл.';
    } finally {
        current.saving = false;
    }
}
function beginBatchPreview(selected, room, question) {
    if (!canEdit.value) return;
    const available = Math.max(0, 12 - mediaCountAt(room, question));
    const accepted = [];
    let rejected = 0;
    for (const original of selected) {
        if (accepted.length >= available) { rejected++; continue; }
        const mime = focusFileMime(original);
        const file = original.type ? original : new File([original], original.name, { type: mime });
        const validationError = allowedFocusFile(file);
        if (validationError) { mediaError.value = validationError; rejected++; continue; }
        accepted.push({
            id: crypto.randomUUID(), file, mime, url: URL.createObjectURL(file),
        });
    }
    if (!accepted.length) {
        if (!mediaError.value) mediaError.value = 'Нет места для новых файлов: не больше 12 вложений на вопрос.';
        return;
    }
    closeBatchPreview();
    batchPreview.value = { room, question, items: accepted, index: 0, saving: false };
    if (rejected) mediaError.value = 'Выбраны только подходящие файлы в пределах лимита 12 вложений.';
}
function closeBatchPreview() {
    if (batchPreview.value?.saving) return;
    for (const item of batchPreview.value?.items ?? []) URL.revokeObjectURL(item.url);
    batchPreview.value = null;
}
function dropBatchFile(id) {
    const preview = batchPreview.value;
    if (!preview || preview.saving) return;
    const file = preview.items.find(item => item.id === id);
    if (file) URL.revokeObjectURL(file.url);
    preview.items = preview.items.filter(item => item.id !== id);
    preview.index = Math.max(0, Math.min(preview.index, preview.items.length - 1));
    if (!preview.items.length) closeBatchPreview();
}
async function confirmBatch() {
    const preview = batchPreview.value;
    if (!preview || preview.saving || !preview.items.length) return;
    preview.saving = true;
    try {
        const queued = await queueSelectedFiles(preview.items.map(item => item.file), preview.room, preview.question);
        if (queued) {
            preview.saving = false;
            closeBatchPreview();
        } else {
            preview.saving = false;
        }
    } catch (error) {
        mediaError.value = error.message || 'Не удалось добавить выбранные файлы.';
        preview.saving = false;
    }
}

function beginSavedReplacement(event, room, question, previous) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!canEdit.value || !file || !previous?.upload_id) return;
    if (mediaCountAt(room, question) >= 12) {
        mediaError.value = 'Лимит 12 вложений. Сначала удалите один файл, затем загрузите замену.';
        return;
    }
    if (busyUploads.value || attachInFlight) {
        mediaError.value = 'Дождитесь завершения текущих загрузок перед заменой.';
        return;
    }
    const mime = focusFileMime(file);
    beginCapture(file, room, question, mime.startsWith('video/') ? 'video' : 'image');
    if (photoCapture.value) photoCapture.value.replaceUploadId = previous.upload_id;
}

function openLightbox(file) {
    if (mediaIsVideo(file)) return;
    // Gallery tiles load inexpensive 360px thumbnails; fullscreen must open
    // the original file. HEIC may not be supported by the browser, in which
    // case the generated JPEG thumbnail is a safe fallback.
    const url = file.preview_url || file.url || file.thumbnail_url || '';
    if (url) lightbox.value = {
        url, fallback_url: file.thumbnail_url || '', failed: false,
        name: file.name || file.original_name || 'Фотография',
    };
}
function lightboxImageFailed() {
    if (!lightbox.value) return;
    if (lightbox.value.fallback_url && lightbox.value.url !== lightbox.value.fallback_url) {
        lightbox.value = { ...lightbox.value, url: lightbox.value.fallback_url };
    } else {
        lightbox.value = { ...lightbox.value, failed: true };
    }
}
function closeLightbox() { lightbox.value = null; }
function openVideoLightbox(file) {
    const url = mediaPreview(file);
    if (url) videoLightbox.value = { url, name: file.name || file.original_name || 'Видео' };
}
function closeVideoLightbox() { videoLightbox.value = null; }
function galleryEntries(room, question) {
    return visibleMediaAt(room, question).filter(entry => Boolean(entry.url));
}
function openGallery(room, question, selected) {
    const entries = galleryEntries(room, question);
    const index = entries.findIndex(entry => entry.key === selected.key);
    if (index < 0) return;
    galleryViewer.value = { room, question, index };
}
const galleryItems = computed(() => galleryViewer.value
    ? galleryEntries(galleryViewer.value.room, galleryViewer.value.question) : []);
async function removeGalleryItem(item) {
    if (!galleryViewer.value || !item) return;
    const { room, question } = galleryViewer.value;
    galleryViewer.value = null;
    if (item.kind === 'saved') await removeSavedMedia(room, question, item.source);
    else if (item.kind === 'upload') await cancelFile(item.source);
}


async function choosePerson(id) {
    if (!canEdit.value) return;
    if (id === '__test__') {
        if (viewerRole.value !== 'admin' || testMode.value) return;
        if (busyUploads.value || recoveringDraftMedia || attachInFlight || recoveryAction.value) {
            syncError.value = 'Завершите или отмените реальные загрузки перед переходом в тест.';
            return;
        }
        if (!(await ensureSynced())) {
            syncError.value = syncError.value || 'Сначала сохраните рабочий черновик.';
            return;
        }
        try {
            // New or resumed test draft comes from the same authenticated
            // backend, but lives in state=sandbox, never in real controls.
            const data = await fetchJson(props.bootstrapUrl + '?test_mode=1');
            if (!data.test_mode || data.viewer?.role !== 'admin') {
                throw new Error('Сервер не подтвердил безопасный тестовый режим.');
            }
            cleanupPreviews();
            testMode.value = true;
            uploads.value = [];
            pickerFor.value = null;
            mediaError.value = '';
            cacheWarning.value = '';
            control.value = data.control;
            rooms.value = data.rooms;
            people.value = data.people ?? [];
            apartments.value = data.apartments ?? [];
            setup.value = data.setup;
            answers.value = data.answers;
            media.value = data.media;
            interruptedUploads.value = data.pending_uploads ?? [];
            run.value = data.run;
            recoveryKey = recoveryScope(data.viewer.id, data.control.id, data.control.schema_version) + ':sandbox';
            readResumePosition();
            changeSerial = 0;
            acknowledgedSerial = 0;
            uncertainSnapshot = null;
            pendingAttachReplay = null;
            clearTimeout(timer);
            finalResult.value = null;
            state.value = data.run?.schema_conflict ? 'conflict' : 'synced';
            syncError.value = data.run?.schema_conflict ? 'Тестовый шаблон изменился. Завершите старый тест отдельно.' : '';
            stage.value = 'setup';
            if (!data.run?.schema_conflict) {
                await restoreDraftMedia();
                if (!data.run?.draft_id) touch();
            }
        } catch (error) {
            syncError.value = error.message || 'Не удалось открыть серверный тестовый режим.';
        }
        return;
    }
    if (testMode.value) return;
    setup.value.cleaner_id = id;
    clearSetupIssue('cleaner_id');
    touch();
}
function chooseApartment(id) {
    if (!canEdit.value) return;
    setup.value.apartment_id = id;
    clearSetupIssue('apartment_id');
    touch();
}
function choosePreviousCleaner(name) {
    if (!canEdit.value) return;
    setup.value.previous_cleaner = String(name ?? '');
    touch();
}
async function exitTestMode() {
    if (!testMode.value) return;
    // Never switch the active Vue run while a test upload/save callback is
    // in flight: that could otherwise write to the newly bootstrapped real run.
    if (busyUploads.value || recoveringDraftMedia || attachInFlight || recoveryAction.value || inFlight || submitting.value) {
        syncError.value = 'Сначала завершите или отмените тестовые загрузки и дождитесь сохранения ответов.';
        return;
    }
    if (!finalResult.value && !(await ensureSynced())) {
        syncError.value = syncError.value || 'Сначала сохраните тестовый черновик.';
        return;
    }
    finalResult.value = null;
    await initialize(); // Restore the real server-owned draft, never test values.
}
async function startNewSandboxTest() {
    if (!testMode.value || stage.value !== 'done' || submitting.value || busyUploads.value) return;
    finalResult.value = null;
    await initialize();
    if (!loadError.value) await choosePerson('__test__');
}
function resumeDraftFile(event, record) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !canEdit.value || recoveryAction.value || record.run_id !== run.value.id) return;
    recoveryFileError.value = null;
    if (file.name !== record.original_name || file.size !== Number(record.size) || focusFileMime(file) !== record.mime) {
        recoveryFileError.value = { id: record.upload_id, message: 'Это другой файл. Выберите оригинал или переснимите материал.' };
        return;
    }
    const error = allowedFocusFile(file);
    if (error) { recoveryFileError.value = { id: record.upload_id, message: error }; return; }
    const previous = uploads.value.find(item => item.id === record.upload_id);
    if (previous && ['queued', 'uploading', 'attaching'].includes(previous.status)) return;
    const data = {
        id: record.upload_id, file, record, room: record.room_index,
        question: record.question_index, status: 'queued', progress: 0, error: '',
        controller: null, info: null, preview_url: URL.createObjectURL(file),
    };
    if (previous) {
        if (previous.preview_url) URL.revokeObjectURL(previous.preview_url);
        Object.assign(previous, data);
    } else uploads.value.push(data);
    pumpTransfers();
}
function openRecoveryQuestion(record, showPicker = false) {
    if (!rooms.value[record.room_index]?.items?.[record.question_index]) return;
    const earlier = firstMissing(record.room_index - 1);
    if (earlier) {
        jumpToQuestion(earlier.roomIndex, earlier.questionIndex, 'missing');
        return;
    }
    goRoom(record.room_index);
    highlightedReviewQuestion.value = record.question_index;
    void nextTick().then(() => {
        if (activeRoom.value !== record.room_index || stage.value !== 'questions') return;
        document.getElementById('focus-question-' + record.room_index + '-' + record.question_index)?.scrollIntoView({ block: 'start' });
        if (showPicker) pickerFor.value = pickerKey(record.room_index, record.question_index);
    });
}
async function discardInterruptedDraft(record, replaceFile = false) {
    if (!canEdit.value || recoveryAction.value || record.status !== 'pending' || record.run_id !== run.value.id) return;
    if (uploads.value.some(item => item.id === record.upload_id && ['queued', 'uploading', 'attaching'].includes(item.status))) return;
    if (!window.confirm(replaceFile
        ? 'Прервать старую загрузку и добавить новый материал к этому вопросу?'
        : 'Убрать эту незавершённую загрузку из контроля? Ответы и другие вложения останутся.')) return;
    recoveryAction.value = record.upload_id;
    recoveryFileError.value = null;
    try {
        const current = await focusMediaRequest(record.status_url, 'GET', undefined, csrf);
        if (current.status === 'ready' || current.status === 'attached') {
            record.status = 'ready';
            await attachReadyDraft(record);
            return;
        }
        if (current.status !== 'cancelled') await focusMediaRequest(current.urls.cancel, 'DELETE', undefined, csrf);
        interruptedUploads.value = interruptedUploads.value.filter(item => item.upload_id !== record.upload_id);
        const local = uploads.value.find(item => item.id === record.upload_id);
        if (local?.preview_url) URL.revokeObjectURL(local.preview_url);
        uploads.value = uploads.value.filter(item => item.id !== record.upload_id);
        if (local?.cachePromise) await local.cachePromise;
        if (recoveryKey) void forgetCachedFocusPhoto(recoveryKey, record.upload_id).catch(() => {});
        if (replaceFile) openRecoveryQuestion(record, true);
    } catch (error) {
        recoveryFileError.value = { id: record.upload_id, message: error.message || 'Не удалось отменить загрузку. Попробуйте ещё раз.' };
    } finally {
        recoveryAction.value = null;
    }
}
async function autoAttachReadyDraftFiles() {
    // A "ready" upload already exists in full on the server. Avoid forcing
    // supervisors to search for an original file or press Attach by hand
    // after reopening Telegram. Serialize attachment revision updates.
    for (const record of [...interruptedUploads.value]) {
        if (!canEdit.value) return;
        if (record.status === 'ready') await attachReadyDraft(record);
    }
}

async function attachReadyDraft(record) {
    if (!canEdit.value || record.status !== 'ready' || record.run_id !== run.value.id) return;
    if (activeTransfers || attachInFlight) {
        mediaError.value = 'Подождите завершения текущих загрузок.';
        return;
    }
    mediaError.value = '';
    try {
        await writeDraftMedia(async () => {
            if (record.run_id !== run.value.id
                || !interruptedUploads.value.some(item => item.upload_id === record.upload_id)) return;
            const info = await focusMediaRequest(record.status_url, 'GET', undefined, csrf);
            if (info.status !== 'ready' && info.status !== 'attached') {
                throw new Error('Файл ещё не готов. Выберите исходный файл для возобновления.');
            }
            const publish = attached => {
                run.value = { ...run.value, ...attached.run };
                if (changeSerial !== acknowledgedSerial) saveLocalSnapshot();
                const files = media.value[record.room_index][record.question_index];
                if (!files.some(file => file.upload_id === attached.media_item.upload_id)) files.push(attached.media_item);
                interruptedUploads.value = interruptedUploads.value.filter(item => item.upload_id !== record.upload_id);
                if (recoveryKey) void forgetCachedFocusPhoto(recoveryKey, record.upload_id).catch(() => {});
            };
            const body = { draft_id: run.value.draft_id, expected_revision: run.value.revision };
            // Already attached on bootstrap means a previous request was confirmed
            // by the server snapshot, not a new revision-changing write.
            if (info.status === 'attached') publish(await attachFocusFile(info.urls.attach, body, csrf));
            else await saveMediaAttachment({ url: info.urls.attach, body, publish, uploadId: record.upload_id });
        });
    } catch (error) {
        mediaError.value = error.message || 'Не удалось прикрепить готовый файл.';
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
    if (!['options', 'both'].includes(question.answer_type ?? 'options') || !options.length) return false;
    const value = String(selected ?? '').trim();
    const score = option => Math.trunc(Number(option.points ?? 0)) || 0;
    const maximum = Math.max(0, ...options.map(score));
    const points = options.reduce((result, option, index) => {
        const matches = [option.value ?? option.label ?? '', option.label ?? '', 'option_' + index];
        return value && matches.some(match => String(match).trim() === value) ? score(option) : result;
    }, 0);
    return maximum > 0 && points < maximum;
}

const reviewCleanerName = computed(() => testMode.value || setup.value.cleaner_id === '__test__'
    ? 'Тестовый сотрудник'
    : people.value.find(person => String(person.id) === String(setup.value.cleaner_id))?.name || 'Не выбран');
const reviewApartmentName = computed(() => apartments.value
    .find(apartment => String(apartment.id) === String(setup.value.apartment_id))?.name || 'Не выбрана');
const reviewFindings = computed(() => {
    const findings = [];
    rooms.value.forEach((room, roomIndex) => {
        if (room.is_optional) return;
        (room.items ?? []).forEach((question, questionIndex) => {
            if (question.is_optional) return;
            const answer = answers.value[roomIndex]?.[questionIndex];
            if (!isNegative(question, answer?.selected)) return;
            const options = question.answer_options_scored ?? [];
            const selectedOption = options.find((option, i) => optionValue(option, i) === String(answer.selected)
                || String(option.label) === String(answer.selected));
            findings.push({
                key: roomIndex + ':' + questionIndex, roomIndex, questionIndex,
                roomTitle: room.title || 'Комната ' + (roomIndex + 1),
                title: question.question || 'Вопрос ' + (questionIndex + 1),
                answer: selectedOption?.label || answer?.selected || answer?.custom || 'Замечание',
                mediaCount: mediaCountAt(roomIndex, questionIndex),
            });
        });
    });
    return findings;
});
const reviewMediaStats = computed(() => {
    let photos = 0, videos = 0;
    rooms.value.forEach((room, roomIndex) => {
        (room.items ?? []).forEach((_, questionIndex) => {
            for (const item of visibleMediaAt(roomIndex, questionIndex)) {
                if (mediaIsVideo(item)) videos++;
                else photos++;
            }
        });
    });
    return { photos, videos, total: photos + videos, waiting: outstandingMedia.value };
});
async function openReviewFinding(finding) {
    goRoom(finding.roomIndex);
    highlightedReviewQuestion.value = finding.questionIndex;
    await nextTick();
    document.getElementById('focus-question-' + finding.roomIndex + '-' + finding.questionIndex)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
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
    changeSerial++;
    state.value = 'dirty';
    syncError.value = '';
    saveLocalSnapshot();
    queueSave();
}

function queueSave(delay = 800) {
    clearTimeout(timer);
    if (!connectionOnline.value) {
        // Local recovery snapshots still continue to be queued in IndexedDB.
        state.value = 'dirty';
        return;
    }
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
        test_mode: testMode.value,
    };
}

async function flush() {
    clearTimeout(timer);
    timer = null;
    if (!canEdit.value) return false;
    if (!connectionOnline.value) {
        state.value = 'dirty';
        syncError.value = localStorageAvailable.value
            ? 'Нет интернета. Изменения остаются на этом устройстве, сервер ещё не подтвердил их.'
            : 'Нет интернета и локальная копия недоступна. Не закрывайте Telegram до восстановления связи.';
        return false;
    }
    if (attachInFlight) {
        await attachInFlight;
        return flush();
    }
    if (inFlight) return inFlight;
    if (pendingAttachReplay) {
        if (!(await reconcilePendingAttachment())) return false;
        return flush();
    }
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
            if (result.run?.id !== body.run_id || !(result.run?.draft_id > 0)
                || !Number.isInteger(result.run?.revision) || result.run.revision < Math.max(1, body.expected_revision)) {
                throw new Error('Сервер не подтвердил сохранение черновика.');
            }
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
    clearTimeout(timer);
    for (let attempt = 0; attempt < 5; attempt++) {
        const ok = await flush();
        if (!ok) return false;
        if (changeSerial === acknowledgedSerial && !inFlight) return true;
    }
    return false;
}

// Only revision-changing requests are serialized. Chunks and their
// reservations do not depend on the latest answers or draft revision.
async function holdDraftMedia(action) {
    let unlock;
    attachInFlight = new Promise(resolve => { unlock = resolve; });
    try {
        return await action();
    } finally {
        attachInFlight = null;
        unlock();
        if (state.value === 'dirty' && changeSerial !== acknowledgedSerial) queueSave(0);
    }
}

async function reconcilePendingAttachment() {
    if (attachInFlight) {
        await attachInFlight;
        return reconcilePendingAttachment();
    }
    const pending = pendingAttachReplay;
    if (!pending) return canEdit.value;
    if (!connectionOnline.value || !canEdit.value) return false;
    return holdDraftMedia(async () => {
        try {
            await pending.replay();
            pendingAttachReplay = null;
            state.value = changeSerial === acknowledgedSerial ? 'synced' : 'dirty';
            syncError.value = '';
            return true;
        } catch (error) {
            // A revision conflict after this replay is real. Do not adopt a
            // different snapshot or retry as if it were our own write.
            if (error.status === 409) {
                pendingAttachReplay = null;
                state.value = 'conflict';
            } else state.value = 'error';
            syncError.value = 'Не удалось сверить прикреплённый файл. Новые ответы остаются в этом черновике на устройстве.';
            return false;
        }
    });
}

async function saveMediaAttachment({ url, body, signal, notify, publish, afterReplay, uploadId }) {
    const perform = async currentSignal => {
        const result = await attachFocusFile(url, body, csrf, currentSignal, notify);
        if (result.run?.revision !== body.expected_revision + 1) {
            const error = new Error('Черновик изменился после прикрепления файла.');
            error.status = 409;
            throw error;
        }
        await publish(result);
    };
    try {
        await perform(signal);
    } catch (error) {
        if (isTransientFocusUploadError(error)) {
            pendingAttachReplay = { uploadId, replay: async () => {
                await perform();
                await afterReplay?.();
            } };
        }
        throw error;
    }
}

async function writeDraftMedia(action) {
    while (true) {
        if (!canEdit.value) throw new Error(syncError.value || 'Черновик сейчас недоступен.');
        if (attachInFlight || inFlight) {
            await (attachInFlight || inFlight);
            continue;
        }
        if (pendingAttachReplay) {
            if (!(await reconcilePendingAttachment())) throw new Error(syncError.value || 'Файл ещё не подтверждён.');
            continue;
        }
        // A lost save acknowledgement may already have advanced the revision.
        // Reconcile it before sending an attachment or a deletion.
        if (uncertainSnapshot) {
            if (!(await flush())) throw new Error(syncError.value || 'Сервер не подтвердил состояние черновика.');
            continue;
        }
        break;
    }
    return holdDraftMedia(action);
}

function positionKey() {
    return recoveryKey ? recoveryKey + ':position' : null;
}
function readResumePosition() {
    resumePosition.value = null;
    const key = positionKey();
    if (!key || !run.value?.id) return;
    try {
        const point = JSON.parse(localStorage.getItem(key));
        if (point?.runId !== run.value.id || !Number.isInteger(point.room) || !rooms.value[point.room]) return;
        const question = Number.isInteger(point.question) && rooms.value[point.room].items?.[point.question]
            ? point.question : null;
        resumePosition.value = { room: point.room, question };
    } catch (_) { /* Position is a convenience; access to answers is unaffected. */ }
}
function rememberPosition(room, question = null) {
    if (!canEdit.value || stage.value !== 'questions' || room !== activeRoom.value || !rooms.value[room]) return;
    const point = { room, question };
    if (resumePosition.value?.room === room && resumePosition.value?.question === question) return;
    resumePosition.value = point;
    const key = positionKey();
    if (!key) return;
    try {
        localStorage.setItem(key, JSON.stringify({ ...point, runId: run.value.id }));
    } catch (_) { /* Best effort, including private WebViews. */ }
}
function clearResumePosition() {
    const key = positionKey();
    if (key) {
        try { localStorage.removeItem(key); } catch (_) { /* A new run also ignores the old position. */ }
    }
    resumePosition.value = null;
}
const setupFieldIds = {
    cleaner_id: 'focus-cleaner',
    apartment_id: 'focus-apartment',
    cleaning_date: 'focus-cleaning-date',
    inspection_date: 'focus-inspection-date',
};

function showSetupIssue(field, message) {
    setupIssue.value = { field, message };
    void nextTick().then(() => {
        const input = document.getElementById(setupFieldIds[field]);
        input?.scrollIntoView({ block: 'center' });
        input?.focus({ preventScroll: true });
    });
}

function validateSetup() {
    const data = setup.value;
    const validDate = value => /^\d{4}-\d{2}-\d{2}$/.test(value ?? '');
    let issue = null;
    if (!testMode.value && !people.value.some(person => String(person.id) === String(data.cleaner_id))) {
        issue = ['cleaner_id', 'Выберите сотрудника.'];
    } else if (!apartments.value.some(apartment => String(apartment.id) === String(data.apartment_id))) {
        issue = ['apartment_id', 'Выберите квартиру.'];
    } else if (!validDate(data.cleaning_date)) {
        issue = ['cleaning_date', 'Укажите дату уборки.'];
    } else if (!validDate(data.inspection_date)) {
        issue = ['inspection_date', 'Укажите дату проверки.'];
    } else if (data.inspection_date < data.cleaning_date) {
        issue = ['inspection_date', 'Дата проверки не может быть раньше даты уборки.'];
    }
    if (issue) { showSetupIssue(...issue); return false; }
    setupIssue.value = null;
    return true;
}

function clearSetupIssue(field) {
    if (setupIssue.value?.field === field) setupIssue.value = null;
}

function editReviewSetup() {
    editingReviewSetup.value = true;
    stage.value = 'setup';
    window.scrollTo({ top: 0, behavior: 'instant' });
}

function startQuestions() {
    if (!validateSetup()) return;
    if (editingReviewSetup.value) {
        editingReviewSetup.value = false;
        openReview();
        void nextTick().then(() => document.getElementById('focus-review-heading')?.focus({ preventScroll: true }));
        window.scrollTo({ top: 0, behavior: 'instant' });
        return;
    }
    const position = resumePosition.value;
    if (position) {
        const earlier = firstMissing(position.room - 1);
        if (earlier) {
            jumpToQuestion(earlier.roomIndex, earlier.questionIndex, 'missing');
            return;
        }
        if (position.question == null) goRoom(position.room);
        else jumpToQuestion(position.room, position.question, 'resume');
        return;
    }
    // Older drafts and browsers without local storage can still start at the first gap.
    if (run.value?.draft_id && completedRequired.value > 0 && firstMissing()) {
        jumpToMissing();
        return;
    }
    goRoom(0);
}
function jumpToQuestion(room, question, reason) {
    if (!rooms.value[room]?.items?.[question]) return;
    goRoom(room);
    rememberPosition(room, question);
    if (reason === 'missing') blockedQuestion.value = question;
    else highlightedReviewQuestion.value = question;
    void nextTick().then(() => {
        if (activeRoom.value !== room || stage.value !== 'questions') return;
        const element = document.getElementById('focus-question-' + room + '-' + question);
        element?.scrollIntoView({ block: 'start' });
        element?.focus({ preventScroll: true });
    });
}
function jumpToMissing() {
    const missing = firstMissing();
    if (missing) jumpToQuestion(missing.roomIndex, missing.questionIndex, 'missing');
}
function jumpToPendingMedia(throughRoom = rooms.value.length - 1, includeActive = true) {
    const record = recoveryNeedingInput.value.find(item => item.room_index <= throughRoom);
    if (record) {
        // The recovery card holds the actions; each card already links back to its question.
        void nextTick().then(() => {
            const element = document.getElementById('focus-recovery-' + record.upload_id);
            element?.scrollIntoView({ block: 'start' });
            element?.focus({ preventScroll: true });
        });
        return true;
    }
    const upload = failedMediaTransfers.value.find(item => item.room <= throughRoom)
        ?? (includeActive ? currentMediaTransfers.value.find(item => item.room <= throughRoom) : null);
    if (!upload) return false;
    jumpToQuestion(upload.room, upload.question, 'media');
    return true;
}
function firstMissing(throughRoom = rooms.value.length - 1) {
    for (let roomIndex = 0; roomIndex <= throughRoom; roomIndex++) {
        const room = rooms.value[roomIndex];
        for (let questionIndex = 0; questionIndex < (room.items ?? []).length; questionIndex++) {
            if (!room.is_optional && !room.items[questionIndex].is_optional && !filled(roomIndex, questionIndex)) {
                return { roomIndex, questionIndex };
            }
        }
    }
    return null;
}

async function showRooms() {
    roomPickerShown.value = true;
    await nextTick();
    roomPicker.value?.querySelector('[aria-current="step"]')?.focus();
}
function closeRooms(restoreFocus = true) {
    roomPickerShown.value = false;
    if (restoreFocus) void nextTick().then(() => roomPickerTrigger.value?.focus());
}
function goRoom(index) {
    if (index >= 0 && index < rooms.value.length) {
        pickerFor.value = null;
        const wasPicking = roomPickerShown.value;
        closeRooms(false);
        activeRoom.value = index;
        blockedQuestion.value = null;
        highlightedReviewQuestion.value = null;
        stage.value = 'questions';
        rememberPosition(index);
        window.scrollTo({ top: 0, behavior: 'instant' });
        if (wasPicking) void nextTick().then(() => document.getElementById('focus-room-heading')?.focus({ preventScroll: true }));
    }
}

function nextRoom() {
    const missing = firstMissing(activeRoom.value);
    if (missing) {
        jumpToQuestion(missing.roomIndex, missing.questionIndex, 'missing');
        return;
    }
    // A healthy transfer may finish while the inspector answers in the next room.
    // Errors and missing originals must be dealt with before moving forward.
    if (jumpToPendingMedia(activeRoom.value, false)) return;
    if (activeRoom.value + 1 < rooms.value.length) {
        goRoom(activeRoom.value + 1);
        return;
    }
    openReview();
}

function openReview() {
    const missing = firstMissing();
    if (missing) {
        jumpToQuestion(missing.roomIndex, missing.questionIndex, 'missing');
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
                test_mode: testMode.value,
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
        jumpToQuestion(missing.roomIndex, missing.questionIndex, 'missing');
        return;
    }
    submitting.value = true;
    submitError.value = '';
    try {
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
                test_mode: testMode.value,
            }),
        });
        if (!result.submitted || (!testMode.value && !result.result_url)
            || (testMode.value && !result.test_mode)) {
            throw new Error('Сервер не подтвердил завершение проверки.');
        }
        finalResult.value = result;
        clearResumePosition();
        stage.value = 'done';
        state.value = 'synced';
        if (recoveryKey) void queueLocalWrite({ type: 'delete', scope: recoveryKey });
    } catch (error) {
        const invalidSetup = error.status === 422 && Object.keys(setupFieldIds).find(field => error.fields?.[field]?.length);
        if (invalidSetup) {
            editReviewSetup();
            showSetupIssue(invalidSetup, error.fields[invalidSetup][0]);
            return;
        }
        submitError.value = error.message || 'Не удалось завершить контроль. Повторите отправку.';
        if (error.status === 409 && error.code !== 'submission_in_progress') {
            state.value = 'conflict';
        }
    } finally {
        submitting.value = false;
    }
}

async function resetControl() {
    if (!props.resetUrl || !run.value || testMode.value || stage.value === 'done' || resetting.value) return;
    if (busyUploads.value || recoveringDraftMedia || attachInFlight || recoveryAction.value || inFlight || submitting.value || uncertainSnapshot) {
        syncError.value = 'Дождитесь сохранения или отмените загрузки перед сбросом.';
        return;
    }
    if (!window.confirm('Сбросить контроль? Все ответы и материалы текущего черновика будут убраны.')) return;

    resetting.value = true;
    clearTimeout(timer);
    timer = null;
    try {
        await fetchJson(props.resetUrl, {
            method: 'POST',
            body: JSON.stringify({
                control_id: control.value.id,
                run_id: run.value.id,
                draft_id: run.value.draft_id,
                expected_revision: run.value.revision,
            }),
        });
        if (recoveryKey) {
            await queueLocalWrite({ type: 'delete', scope: recoveryKey });
            for (const photo of await readCachedFocusPhotos(recoveryKey)) {
                if (photo.run_id === run.value.id || photo.run_id === recoveryRecord.value?.run_id) {
                    await forgetCachedFocusPhoto(recoveryKey, photo.upload_id);
                }
            }
        }
        clearResumePosition();
        finalResult.value = null;
        await initialize();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (error) {
        syncError.value = error.message || 'Не удалось сбросить контроль.';
    } finally {
        resetting.value = false;
    }
}

function pendingAt(room, question) {
    return uploads.value.filter(item => item.room === room && item.question === question
        && ['queued', 'uploading', 'attaching', 'error'].includes(item.status));
}
function mediaCountAt(room, question) {
    // The server may confirm an upload before the asynchronous client queue
    // removes its temporary tile. Count unique file IDs, never both states.
    const ids = new Set();
    for (const file of media.value[room]?.[question] ?? []) {
        ids.add(file.upload_id || 'attached:' + file.index);
    }
    for (const item of pendingAt(room, question)) ids.add(item.id);
    for (const item of interruptedUploads.value) {
        if (item.room_index === room && item.question_index === question) ids.add(item.upload_id);
    }
    return ids.size;
}

function addFiles(event, room, question) {
    const selected = Array.from(event.target.files ?? []);
    event.target.value = '';
    pickerFor.value = null;
    if (selected.length) beginBatchPreview(selected, room, question);
}

async function queueSelectedFiles(selected, room, question, options = {}) {
    if (!canEdit.value) return false;
    mediaError.value = '';
    const beforeDraft = run.value.draft_id == null;
    const firstBackups = [];
    let accepted = 0;
    let reserved = mediaCountAt(room, question);
    for (const original of selected) {
        const mime = focusFileMime(original);
        const file = original.type ? original : new File([original], original.name, { type: mime });
        const error = allowedFocusFile(file);
        if (error) {
            mediaError.value = error;
            continue;
        }
        if (reserved >= 12) {
            mediaError.value = 'На один вопрос можно добавить не больше 12 вложений.';
            break;
        }
        const id = crypto.randomUUID();
        // Show the thumbnail immediately. IndexedDB can take hundreds of
        // milliseconds for a multi-megabyte file; don't block the entire
        // gallery while the browser writes an emergency on-device backup.
        uploads.value.push({
            id, file, room, question, status: 'queued',
            progress: 0, error: '', retryHint: '', controller: null, info: null,
            replaceUploadId: options.replaceUploadId || null,
            preview_url: URL.createObjectURL(file), cached: false, cachePending: false,
        });
        const queued = uploads.value[uploads.value.length - 1];
        if (canCacheFocusMedia(file)) {
            queued.cachePending = true;
            queued.cachePromise = cacheFocusPhoto({
                scope: recoveryKey, uploadId: id, runId: run.value.id,
                file, room, question, beforeDraft,
            }).then(() => {
                queued.cached = true;
            }).catch(() => {
                cacheWarning.value = 'Не удалось сохранить резервную копию на устройстве. Не закрывайте Telegram до подтверждения загрузки.';
            }).finally(() => {
                queued.cachePending = false;
            });
            if (beforeDraft) firstBackups.push(queued.cachePromise);
        } else if (mime.startsWith('video/')) {
            cacheWarning.value = 'Большое видео нельзя безопасно хранить в памяти Telegram. Дождитесь сохранения на сервере или сохраните исходник для восстановления.';
        }
        reserved++;
        accepted++;
    }
    // Don't close the first-file preview before IndexedDB has attempted to
    // persist it, otherwise closing Telegram immediately can lose the file.
    await Promise.all(firstBackups);
    pumpTransfers();
    return accepted > 0;
}

function pumpTransfers() {
    if (!connectionOnline.value) return;
    while (activeTransfers < 2 && (canEdit.value || stage.value === 'done')) {
        const next = uploads.value.find(item => item.status === 'queued' && !item.cancelling);
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
    item.retryHint = '';
    try {
        // A WebView that closes mid-upload must be able to resume this file.
        // Let emergency on-device caching finish before network transfer.
        if (item.cachePromise) await item.cachePromise;
        if (controller.signal.aborted) return;
        if (!item.record && !run.value?.draft_id) {
            if (!inFlight && changeSerial === acknowledgedSerial) touch();
            if (!(await flush()) || !run.value?.draft_id) {
                const error = new Error(syncError.value || 'Сервер не подтвердил создание черновика.');
                if (!connectionOnline.value || uncertainSnapshot) error.name = 'TimeoutError';
                throw error;
            }
        }
        if (controller.signal.aborted) return;
        const context = item.record
            ? {
                control_id: item.record.control_id,
                draft_id: item.record.draft_id,
                run_id: item.record.run_id,
                schema_version: item.record.schema_version,
                test_mode: testMode.value,
            }
            : {
                control_id: control.value.id,
                draft_id: run.value.draft_id,
                run_id: run.value.id,
                schema_version: control.value.schema_version,
                test_mode: testMode.value,
            };

        item.reservationAttempted = true;
        const result = await transferFocusFile({
            startUrl: props.mediaStartUrl,
            file: item.file,
            uploadId: item.id,
            draft: context,
            room: item.room,
            question: item.question,
            csrf,
            signal: controller.signal,
            onProgress: progress => {
                item.progress = progress;
                item.retryHint = '';
            },
            onInit: info => { item.info = info; },
            onRetry: notice => {
                item.retryHint = notice.label + ': восстанавливаем соединение (' + notice.attempt + '/2)';
            },
        });
        if (controller.signal.aborted) return;
        item.status = 'attaching';

        const task = attachQueue.catch(() => {}).then(async () => {
            const attach = async () => {
                if (controller.signal.aborted) return;
                const body = { draft_id: context.draft_id, expected_revision: finalResult.value ? 0 : run.value.revision };
                const notify = notice => {
                    item.retryHint = notice.label + ': восстанавливаем соединение (' + notice.attempt + '/2)';
                };
                if (finalResult.value) {
                    await attachFocusFile(result.urls.attach, body, csrf, controller.signal, notify);
                    await refreshMediaReview();
                    return;
                }
                const publish = async attached => {
                    run.value = { ...run.value, ...attached.run };
                    if (changeSerial !== acknowledgedSerial) saveLocalSnapshot();
                    const arr = media.value[item.room] ?? (media.value[item.room] = []);
                    const files = arr[item.question] ?? (arr[item.question] = []);
                    if (!files.some(file => file.upload_id === attached.media_item.upload_id)) {
                        files.push(attached.media_item);
                    }
                    if (item.replaceUploadId) {
                        // Preserve the original evidence until the replacement
                        // was fully received and attached. Only then remove
                        // it using the same server revision lock.
                        const previous = files.find(file => file.upload_id === item.replaceUploadId);
                        if (previous) {
                            try {
                                const removed = await focusMediaRequest(
                                    props.mediaStartUrl + '/' + encodeURIComponent(previous.upload_id) + '/attached',
                                    'DELETE', { expected_revision: run.value.revision }, csrf, controller.signal);
                                run.value = { ...run.value, ...removed.run };
                                media.value[item.room][item.question] = files.filter(file => file.upload_id !== previous.upload_id);
                            } catch (replaceError) {
                                // Never discard either file when the final
                                // delete acknowledgement is lost.
                                mediaError.value = 'Новый файл сохранён, но старый не удалён. Удалите его вручную: '
                                    + (replaceError.message || 'сервер не подтвердил замену');
                            }
                        }
                    }
                };
                if (result.status === 'attached') {
                    const confirmed = await attachFocusFile(result.urls.attach, body, csrf, controller.signal, notify);
                    await publish(confirmed);
                } else {
                    await saveMediaAttachment({ url: result.urls.attach, body, signal: controller.signal, notify,
                        publish, afterReplay: () => finishUploadedItem(item), uploadId: item.id });
                }
            };
            return finalResult.value ? attach() : writeDraftMedia(attach);
        });
        attachQueue = task.catch(() => {});
        await task;
        await finishUploadedItem(item);
    } catch (error) {
        if (controller.signal.aborted) {
            // Cancellation may still be awaiting its own server confirmation.
            if (!item.cancelling && item.status !== 'error') item.status = 'cancelled';
            return;
        }
        item.status = 'error';
        item.retryable = isTransientFocusUploadError(error);
        item.error = error.message || 'Не удалось загрузить файл.';
        if (error.status === 409 && item.progress === 100) {
            syncError.value = 'Не удалось прикрепить файл из-за изменения черновика. Проверьте ответы перед повтором.';
        }
    }
}

async function finishUploadedItem(item) {
    interruptedUploads.value = interruptedUploads.value.filter(other => other.upload_id !== item.id);
    if (item.cached && recoveryKey) {
        try { await forgetCachedFocusPhoto(recoveryKey, item.id); } catch (_) { /* Bootstrap will clear the confirmed copy. */ }
    }
    if (item.preview_url) URL.revokeObjectURL(item.preview_url);
    uploads.value = uploads.value.filter(other => other.id !== item.id);
}

function retryFile(item) {
    if (pendingAttachReplay?.uploadId === item.id) {
        void flush().then(() => pumpTransfers());
        return;
    }
    if (!canRetryFocusFile(item, canEdit.value, finalResult.value?.response_id)) return;
    item.status = 'queued';
    item.error = '';
    item.retryHint = '';
    item.retryable = false;
    pumpTransfers();
}

async function cancelFile(item) {
    if (item.cancelling || !['queued', 'uploading', 'error'].includes(item.status)) return;
    if (pendingAttachReplay?.uploadId === item.id) {
        item.error = 'Сначала подтвердите состояние файла кнопкой «Повторить».';
        return;
    }
    if (!item.reservationAttempted && !item.info && !item.record) {
        // No POST /media/start ever left this client. No server draft or
        // reservation is needed to remove a local-only file.
        item.cancelling = true;
        item.controller?.abort();
        try {
            if (item.cachePromise) await item.cachePromise;
            if (item.cached && recoveryKey) await forgetCachedFocusPhoto(recoveryKey, item.id);
            if (item.preview_url) URL.revokeObjectURL(item.preview_url);
            uploads.value = uploads.value.filter(other => other.id !== item.id);
        } catch (_) {
            item.status = 'error';
            item.retryable = false;
            item.error = 'Не удалось удалить локальную копию. Нажмите «Убрать» ещё раз.';
        } finally { item.cancelling = false; }
        return;
    }

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
    if (!testMode.value || !item.file?.local) {
        try {
            // An abort can race a POST /media/start. If the client has not
            // received its reservation response, replay the same start ID
            // before cancel. This makes cancellation server-confirmed rather
            // than leaving a ghost pending upload blocking final submission.
            if (!item.info) {
                const context = item.record || {
                    control_id: control.value.id, draft_id: run.value.draft_id,
                    run_id: run.value.id, schema_version: control.value.schema_version,
                };
                item.info = await focusMediaRequest(props.mediaStartUrl, 'POST', {
                    upload_id: item.id, test_mode: testMode.value,
                    control_id: context.control_id, draft_id: context.draft_id,
                    run_id: context.run_id, schema_version: context.schema_version,
                    room_index: item.room, question_index: item.question,
                    original_name: item.file.name, mime: focusFileMime(item.file),
                    size: item.file.size,
                }, csrf);
            }
            if (item.info.status === 'attached') {
                throw new Error('Фото уже сохранено в черновике. Удалите его из галереи.');
            }
            await focusMediaRequest(item.info.urls.cancel, 'DELETE', undefined, csrf);
        } catch (error) {
            item.status = 'error';
            item.error = error.message || 'Не удалось отменить файл на сервере. Повторите отмену после восстановления связи.';
            return;
        }
    }
    interruptedUploads.value = interruptedUploads.value.filter(record => record.upload_id !== item.id);
    // Cancellation may happen while IndexedDB is still writing a backup.
    // Waiting avoids leaving a stale video/photo blob after server cancellation.
    if (item.cachePromise) await item.cachePromise;
    if (recoveryKey && item.cached) {
        void forgetCachedFocusPhoto(recoveryKey, item.id).catch(() => {});
    }
    if (item.preview_url) URL.revokeObjectURL(item.preview_url);
    uploads.value = uploads.value.filter(other => other.id !== item.id);
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
async function removeSavedMedia(room, question, file) {
    if (!canEdit.value || !file) return;
    if (testMode.value && file.local) {
        removeTestMedia(room, question, file);
        return;
    }
    if (!file.upload_id || busyUploads.value || attachInFlight) return;
    if (!window.confirm('Удалить это фото из черновика?')) return;
    mediaError.value = '';
    try {
        await writeDraftMedia(async () => {
            const data = await focusMediaRequest(props.mediaStartUrl + '/' + encodeURIComponent(file.upload_id) + '/attached',
                'DELETE', { expected_revision: run.value.revision }, csrf);
            run.value = { ...run.value, ...data.run };
            if (changeSerial !== acknowledgedSerial) saveLocalSnapshot();
            media.value[room][question] = media.value[room][question].filter(item => item !== file);
            if (recoveryKey) void forgetCachedFocusPhoto(recoveryKey, file.upload_id).catch(() => {});
        });
    } catch (error) {
        mediaError.value = error.message || 'Не удалось удалить фото. Повторите попытку.';
    }
}

function visibleMediaAt(room, question) {
    const savedIds = new Set((media.value[room]?.[question] ?? [])
        .map(file => file.upload_id).filter(Boolean));
    const saved = (media.value[room]?.[question] ?? []).map(file => ({
        kind: 'saved', key: file.upload_id || 'stored-' + file.index,
        source: file, url: mediaPreview(file), name: file.name,
        status: 'saved', mime: file.mime, progress: 100,
    }));
    const active = uploads.value.filter(item => item.room === room && item.question === question
        && item.status !== 'cancelled' && !savedIds.has(item.id)).map(item => ({
            kind: 'upload', key: item.id, source: item, url: mediaPreview(item),
            name: item.file?.name, status: item.status,
            mime: focusFileMime(item.file), progress: item.progress || 0,
        }));
    const interrupted = interruptedUploads.value.filter(item =>
        item.room_index === room && item.question_index === question
        && !savedIds.has(item.upload_id)
        && !uploads.value.some(current => current.id === item.upload_id))
        .map(item => ({
            kind: 'interrupted', key: item.upload_id, source: item,
            url: '', name: item.original_name, status: item.status,
            mime: item.mime, progress: 0,
        }));
    return [...saved, ...active, ...interrupted];
}

function pickerKey(room, question) { return room + '-' + question; }
function togglePicker(room, question) {
    const key = pickerKey(room, question);
    pickerFor.value = pickerFor.value === key ? null : key;
}
function mediaIsVideo(file) { return String(file.mime || focusFileMime(file.file || {})).startsWith('video/'); }
function mediaPreview(file) {
    // Video thumbnails are images (or a placeholder). Never use them as the
    // <video src>, otherwise the player receives JPEG bytes and cannot play.
    if (mediaIsVideo(file)) return file.preview_url || file.url || '';
    return file.preview_url || file.thumbnail_url || file.url || '';
}

function handleNetworkUpdate() {
    const wasOnline = connectionOnline.value;
    connectionOnline.value = navigator.onLine !== false;
    if (!connectionOnline.value) return;
    if (!wasOnline) {
        if (pendingAttachReplay || ['dirty', 'error'].includes(state.value)) queueSave(200);
        // Retry only errors caused by temporary transport failures;
        // permissions/invalid files must remain visible for manual action.
        for (const item of uploads.value) {
            if (item.status === 'error' && item.retryable) retryFile(item);
        }
        pumpTransfers();
    }
}
function warnBeforeUnload(event) {
    if (submitting.value || busyUploads.value || state.value === 'dirty' || state.value === 'saving' || state.value === 'error') {
        event.preventDefault();
        event.returnValue = '';
    }
}

onMounted(() => {
    window.addEventListener('beforeunload', warnBeforeUnload);
    window.addEventListener('online', handleNetworkUpdate);
    window.addEventListener('offline', handleNetworkUpdate);
    window.addEventListener('pagehide', stopPageCamera);
    void initialize();
});
function stopPageCamera() { camera.value = null; }
onBeforeUnmount(() => {
    closePhotoCapture();
    closeBatchPreview();
    closeLightbox();
    closeVideoLightbox();
    galleryViewer.value = null;
    cleanupPreviews();
    clearTimeout(timer);
    window.removeEventListener('beforeunload', warnBeforeUnload);
    window.removeEventListener('online', handleNetworkUpdate);
    window.removeEventListener('offline', handleNetworkUpdate);
    window.removeEventListener('pagehide', stopPageCamera);
});
</script>

<template>
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <FocusCamera v-if="camera" :kind="camera.kind" @close="camera = null" @capture="onCameraCapture" @gallery="onCameraGallery" />
        <div v-if="roomPickerShown" ref="roomPicker" class="fixed inset-0 z-40 flex items-end justify-center bg-slate-950/50 p-3 sm:items-center"
            role="dialog" aria-modal="true" aria-label="Комнаты проверки" @click.self="closeRooms()" @keydown.esc.stop="closeRooms()">
            <section class="max-h-[80dvh] w-full max-w-lg overflow-auto rounded-2xl bg-white p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Комнаты</h2>
                    <button type="button" aria-label="Закрыть список комнат" class="grid size-11 place-items-center rounded-full bg-slate-100" @click="closeRooms()">✕</button>
                </div>
                <nav aria-label="Комнаты" class="space-y-1">
                    <button v-for="(room, index) in rooms" :key="index" type="button"
                        class="flex min-h-12 w-full items-center justify-between gap-3 rounded-xl px-3 text-left text-sm"
                        :class="index === activeRoom ? 'bg-blue-50 font-bold text-blue-800' : index > activeRoom ? 'text-slate-500' : 'hover:bg-slate-50'"
                        :aria-current="index === activeRoom ? 'step' : undefined" :disabled="index > activeRoom"
                        @click="index <= activeRoom && goRoom(index)">
                        <span>{{ room.title || 'Комната ' + (index + 1) }}</span>
                        <span class="shrink-0 text-xs">{{ room.is_optional ? 'Необязательно' : roomCompleted(index) + '/' + roomRequired(index) }}</span>
                    </button>
                </nav>
            </section>
        </div>
        <div v-if="photoCapture" class="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/85 p-3 sm:items-center"
            role="dialog" aria-modal="true" :aria-label="photoCapture.kind === 'video' ? 'Подтвердить видео' : 'Подтвердить фотографию'" @keydown.esc="closePhotoCapture">
            <div class="w-full max-w-lg overflow-hidden rounded-3xl bg-white shadow-2xl">
                <div class="flex items-center justify-between p-4">
                    <h2 class="text-lg font-semibold">{{ photoCapture.kind === 'video' ? 'Оставляем видео?' : 'Оставляем снимок?' }}</h2>
                    <button type="button" :disabled="photoCapture.saving" class="rounded-full p-2 text-slate-600"
                        aria-label="Закрыть предпросмотр" @click="closePhotoCapture">✕</button>
                </div>
                <div class="flex max-h-[56vh] min-h-48 items-center justify-center bg-slate-950">
                    <video v-if="photoCapture.kind === 'video'" :src="photoCapture.preview_url" controls playsinline preload="metadata"
                        class="max-h-[56vh] w-full object-contain" aria-label="Предпросмотр видео до отправки"></video>
                    <img v-else :src="photoCapture.preview_url" alt="Новое фото перед отправкой"
                        class="max-h-[56vh] w-full object-contain">
                </div>
                <div class="space-y-3 p-4">
                    <p v-if="photoCapture.replaceUploadId" class="rounded-lg bg-blue-50 p-2 text-xs font-semibold text-blue-800">
                        Старый файл останется в контроле, пока новый не будет полностью сохранён на сервере.
                    </p>
                    <p class="text-xs text-slate-600">{{ photoCapture.kind === 'video'
                        ? 'Проверьте ролик. После подтверждения он появится в вопросе, а загрузка продолжится, пока Telegram открыт.'
                        : 'Фото появится в вопросе сразу после подтверждения. Сохранение на сервер продолжится автоматически.' }}</p>
                    <p v-if="mediaError" class="rounded-xl bg-red-50 p-3 text-sm text-red-700" role="alert">{{ mediaError }}</p>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" :disabled="photoCapture.saving"
                            class="min-h-12 rounded-2xl bg-slate-100 px-3 text-sm font-semibold text-slate-800 disabled:opacity-50"
                            @click="retakePhoto">Переснять</button>
                        <button type="button" :disabled="photoCapture.saving"
                            class="min-h-12 rounded-2xl bg-blue-600 px-3 text-sm font-semibold text-white disabled:opacity-50"
                            @click="confirmPhoto">{{ photoCapture.saving ? 'Сохраняем…' : photoCapture.kind === 'video' ? 'Оставить видео' : 'Оставить фото' }}</button>
                    </div>
                </div>
            </div>
        </div>
        <div v-if="batchPreview" class="fixed inset-0 z-50 flex items-end justify-center bg-black/85 p-2 sm:items-center"
            role="dialog" aria-modal="true" aria-label="Предпросмотр выбранных файлов">
            <div class="flex w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-white text-slate-900 shadow-2xl"
                style="max-height: 92dvh">
                <header class="flex items-center justify-between gap-3 p-4">
                    <div>
                        <h2 class="text-lg font-bold">Выбранные фото и видео</h2>
                        <p class="text-xs text-slate-500">{{ batchPreview.items.length }} файлов · сначала проверьте их</p>
                    </div>
                    <button type="button" class="grid size-11 place-items-center rounded-full bg-slate-100"
                        :disabled="batchPreview.saving" aria-label="Закрыть предпросмотр файлов"
                        @click="closeBatchPreview">✕</button>
                </header>
                <div v-if="batchPreviewActive" class="relative flex min-h-40 flex-1 items-center justify-center overflow-hidden bg-slate-950"
                    style="max-height: 42dvh">
                    <video v-if="batchPreviewActive.mime.startsWith('video/')" :key="batchPreviewActive.id"
                        :src="batchPreviewActive.url" controls playsinline preload="metadata"
                        class="max-h-full w-full object-contain" aria-label="Просмотр выбранного видео"></video>
                    <img v-else :src="batchPreviewActive.url" alt="Просмотр выбранной фотографии"
                        class="max-h-full w-full object-contain">
                </div>
                <div class="flex gap-2 overflow-x-auto px-4 py-3">
                    <button v-for="(item, i) in batchPreview.items" :key="item.id" type="button"
                        class="relative size-16 shrink-0 overflow-hidden rounded-xl border-2"
                        :class="batchPreview.index === i ? 'border-blue-600' : 'border-slate-200'"
                        :aria-label="'Посмотреть выбранный файл ' + (i + 1)" @click="batchPreview.index = i">
                        <img v-if="item.mime.startsWith('image/')" :src="item.url" alt="" class="size-full object-cover">
                        <span v-else class="grid size-full place-items-center bg-slate-900 text-2xl text-white">▶</span>
                    </button>
                </div>
                <div class="space-y-3 px-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
                    <p class="text-xs text-slate-600">Файлы начнут загружаться только после подтверждения. Можно убрать лишние.</p>
                    <p v-if="mediaError" class="rounded-xl bg-red-50 p-2 text-xs text-red-700" role="alert">{{ mediaError }}</p>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" :disabled="batchPreview.saving" class="min-h-12 rounded-xl bg-slate-100 px-2 text-sm font-semibold"
                            @click="dropBatchFile(batchPreviewActive?.id)">Убрать файл</button>
                        <button type="button" :disabled="batchPreview.saving || !batchPreview.items.length"
                            class="min-h-12 rounded-xl bg-blue-600 px-2 text-sm font-semibold text-white disabled:opacity-50"
                            @click="confirmBatch">{{ batchPreview.saving ? 'Сохраняем…' : 'Добавить ' + batchPreview.items.length }}</button>
                    </div>
                </div>
            </div>
        </div>
        <FocusMediaViewer v-if="galleryViewer && galleryItems.length" :items="galleryItems"
            :initial-index="galleryViewer.index" :can-remove="canEdit"
            @close="galleryViewer = null" @remove="removeGalleryItem" />
        <div class="mx-auto min-h-screen max-w-2xl px-4 pb-24 pt-4 sm:px-6">
            <header class="mb-4 flex items-center justify-between gap-3 border-b border-slate-200 pb-4">
                <div>
                    <h1 class="mt-1 text-xl font-semibold tracking-tight">Контроль качества</h1>
                </div>
                <button v-if="resetUrl && run && !testMode && stage !== 'done' && !run.schema_conflict && !loading && !loadError"
                    type="button" :disabled="resetting || busyUploads || submitting || recoveringPhotos || state === 'saving'"
                    class="ml-auto rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 disabled:opacity-50"
                    @click="resetControl">{{ resetting ? 'Сброс…' : 'Сбросить контроль' }}</button>
                <button v-if="testMode" type="button" :disabled="busyUploads || submitting || state === 'saving'"
                    class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 disabled:opacity-50"
                    @click="exitTestMode">Выйти из теста</button>
                <a v-else-if="stage === 'done' && finalResult?.result_url" :href="finalResult.result_url" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700">Результат</a>
            </header>

            <div v-if="loading" role="status" class="rounded-xl bg-white p-7 text-center text-sm">Загружаем вопросы…</div>
            <div v-else-if="loadError" role="alert" class="rounded-xl bg-white p-5">
                <p class="font-bold text-red-700">{{ loadError }}</p>
                <button type="button" class="mt-3 rounded-xl bg-blue-600 px-4 py-2 font-semibold text-white" @click="initialize">Повторить</button>
            </div>

            <template v-else>
                <div class="sticky top-0 z-20 mb-3 space-y-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-2 text-sm" role="status" aria-live="polite">
                            <span class="size-2.5 shrink-0 rounded-full" :class="state === 'synced' || state === 'idle' ? 'bg-emerald-500' : state === 'saving' ? 'bg-blue-500' : state === 'dirty' ? 'bg-amber-500' : 'bg-red-500'"></span>
                            <strong class="truncate">{{ statusLabel }}</strong>
                        </div>
                        <button v-if="stage !== 'done'" type="button" :disabled="!canEdit || state === 'saving'" class="shrink-0 rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700 disabled:opacity-50" @click="flush">
                            {{ state === 'error' ? 'Повторить' : 'Сохранить' }}
                        </button>
                    </div>
                    <button v-if="stage === 'questions'" ref="roomPickerTrigger" type="button" aria-haspopup="dialog" :aria-expanded="roomPickerShown"
                        class="flex min-h-11 w-full items-center justify-between gap-3 rounded-xl bg-slate-100 px-3 text-left text-sm font-semibold text-slate-800"
                        @click="showRooms">
                        <span>Комнаты · {{ activeRoom + 1 }}/{{ rooms.length }} ▾</span>
                    </button>
                </div>

                <div v-if="testMode" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950" role="status">
                    <strong>Тестовый сотрудник · серверная загрузка</strong>
                    <p class="mt-1">Фото, видео и ответы сохраняются в отдельном тестовом черновике. Рабочие контроли, рейтинги, баллы наград и сообщения Telegram не создаются. Тестовые материалы очищаются автоматически.</p>
                </div>
                <div v-if="!connectionOnline && stage !== 'done'"
                    class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    role="status" aria-live="polite">
                    <strong>Нет интернета</strong>
                    <p class="mt-1">Продолжайте отвечать. Локальная копия создаётся, если браузер разрешает хранение. Файлы и ответы считаются сохранёнными только после подтверждения сервера.</p>
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
                <section v-if="recoveryNeedingInput.length" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                    <h2 class="font-bold">Незавершённые вложения · {{ recoveryNeedingInput.length }}</h2>
                    <p class="mt-1 text-xs">Полностью полученные файлы прикрепятся без повторной загрузки. Если файл не дошёл, выберите оригинал или добавьте новый у нужного вопроса.</p>
                    <div v-for="record in recoveryNeedingInput" :id="'focus-recovery-' + record.upload_id" :key="record.upload_id" tabindex="-1" class="mt-3 scroll-mt-36 rounded-xl bg-white p-3">
                        <button type="button" class="text-left font-semibold text-blue-800" @click="openRecoveryQuestion(record)">
                            {{ rooms[record.room_index]?.title || 'Комната ' + (record.room_index + 1) }} · вопрос {{ record.question_index + 1 }} →
                        </button>
                        <p class="mt-1 break-words text-xs text-slate-700">{{ rooms[record.room_index]?.items?.[record.question_index]?.question }}</p>
                        <p class="mt-1 break-all text-xs text-slate-600">{{ record.mime?.startsWith('video/') ? 'Видео' : 'Фото' }} · {{ record.original_name }} · {{ Math.ceil(Number(record.size) / 1024) }} КБ</p>
                        <p v-if="recoveryFileError?.id === record.upload_id" class="mt-2 text-xs font-semibold text-red-700" role="alert">{{ recoveryFileError.message }}</p>
                        <div v-if="record.status === 'pending'" class="mt-2 flex flex-wrap items-center gap-2">
                            <label class="inline-flex min-h-11 cursor-pointer items-center rounded-lg bg-blue-50 px-3 text-xs font-bold text-blue-800 focus-within:ring-2 focus-within:ring-blue-600">
                                Выбрать оригинал
                                <input type="file" aria-label="Выбрать оригинал" class="sr-only" :accept="record.mime"
                                    :disabled="!canEdit || !!recoveryAction" @change="resumeDraftFile($event, record)">
                            </label>
                            <button type="button" :disabled="!canEdit || !!recoveryAction" class="min-h-11 rounded-lg bg-slate-100 px-3 text-xs font-bold disabled:opacity-50"
                                @click="discardInterruptedDraft(record, true)">{{ recoveryAction === record.upload_id ? 'Отменяем загрузку…' : 'Снять или выбрать заново' }}</button>
                            <button type="button" :disabled="!canEdit || !!recoveryAction" class="min-h-11 rounded-lg px-3 text-xs font-semibold text-red-700 disabled:opacity-50"
                                @click="discardInterruptedDraft(record)">Убрать</button>
                        </div>
                        <button v-else type="button" :disabled="!canEdit || !!recoveryAction" class="mt-2 min-h-11 rounded-lg bg-blue-50 px-3 text-xs font-bold text-blue-800 disabled:opacity-50" @click="attachReadyDraft(record)">Прикрепить готовый файл</button>
                    </div>
                </section>
                <div v-if="mediaError" class="mb-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ mediaError }}</div>
                <div v-if="cacheWarning && outstandingMedia > 0"
                    class="mb-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900" role="status">{{ cacheWarning }}</div>

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
                        <FocusSearchSelect id="focus-cleaner" :items="peopleOptions" :model-value="setup.cleaner_id"
                            label="Сотрудник" placeholder="Найти сотрудника по имени" :disabled="!canEdit"
                            :error="setupIssue?.field === 'cleaner_id' ? setupIssue.message : ''"
                            @select="choosePerson" />
                        <FocusSearchSelect id="focus-apartment" :items="apartments" :model-value="setup.apartment_id"
                            label="Квартира" placeholder="Найти квартиру по названию" :disabled="!canEdit"
                            :error="setupIssue?.field === 'apartment_id' ? setupIssue.message : ''"
                            @select="chooseApartment" />
 

                        <div class="grid grid-cols-2 gap-3">
                            <label class="text-sm font-semibold">Дата уборки
                                <input id="focus-cleaning-date" v-model="setup.cleaning_date" type="date" :disabled="!canEdit"
                                    :aria-invalid="setupIssue?.field === 'cleaning_date' || undefined"
                                    :aria-describedby="setupIssue?.field === 'cleaning_date' ? 'focus-cleaning-date-error' : undefined"
                                    class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white p-3 text-base"
                                    @change="clearSetupIssue('cleaning_date'); touch()">
                                <span v-if="setupIssue?.field === 'cleaning_date'" id="focus-cleaning-date-error" role="alert" class="mt-1 block text-xs text-red-700">{{ setupIssue.message }}</span>
                            </label>
                            <label class="text-sm font-semibold">Дата проверки
                                <input id="focus-inspection-date" v-model="setup.inspection_date" type="date" :disabled="!canEdit"
                                    :aria-invalid="setupIssue?.field === 'inspection_date' || undefined"
                                    :aria-describedby="setupIssue?.field === 'inspection_date' ? 'focus-inspection-date-error' : undefined"
                                    class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white p-3 text-base"
                                    @change="clearSetupIssue('inspection_date'); touch()">
                                <span v-if="setupIssue?.field === 'inspection_date'" id="focus-inspection-date-error" role="alert" class="mt-1 block text-xs text-red-700">{{ setupIssue.message }}</span>
                            </label>
                        </div>
                        <label class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm font-medium">
                            <input v-model="setup.is_assigned" type="checkbox" :disabled="!canEdit" @change="touch">
                            Назначенная уборка
                        </label>
                        <FocusSearchSelect id="focus-previous-cleaner" :items="previousPeopleOptions"
                            :model-value="setup.previous_cleaner" label="Предыдущий сотрудник"
                            placeholder="Найти по имени или оставить пустым"
                            :disabled="!canEdit" @select="choosePreviousCleaner" />

                        <button type="button" :disabled="!canEdit" class="w-full rounded-xl bg-blue-600 p-4 text-base font-bold text-white disabled:opacity-50" @click="startQuestions">
                            {{ editingReviewSetup ? 'Вернуться к сводке' : resumePosition || (run?.draft_id && completedRequired > 0 && firstMissing()) ? 'Продолжить контроль' : 'К вопросам' }}
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
                    </section>

                    <div class="mb-4 flex items-start justify-between gap-2 px-1">
                        <h2 id="focus-room-heading" tabindex="-1" class="text-xl font-semibold">{{ currentRoom.title || 'Комната ' + (activeRoom + 1) }}</h2>
                    </div>

                    <div class="space-y-3">
                        <article v-for="(question, questionIndex) in currentRoom.items" :id="'focus-question-' + activeRoom + '-' + questionIndex"
                            :key="activeRoom + '-' + questionIndex" tabindex="-1"
                            class="scroll-mt-36 rounded-xl border bg-white p-4"
                            @focusin="rememberPosition(activeRoom, questionIndex)" @click.capture="rememberPosition(activeRoom, questionIndex)"
                            :class="blockedQuestion === questionIndex ? 'border-red-400' : highlightedReviewQuestion === questionIndex ? 'border-blue-400 ring-2 ring-blue-100' : 'border-slate-200'">
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


                            <div class="mt-4 border-t border-slate-100 pt-4">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <h4 class="text-sm font-semibold">Фото и видео</h4>
                                        <p class="mt-0.5 text-xs text-slate-500">
                                            {{ mediaCountAt(activeRoom, questionIndex) }} из 12
                                        </p>
                                    </div>
                                    <div class="relative">
                                        <button type="button" :disabled="!canEdit || mediaCountAt(activeRoom, questionIndex) >= 12"
                                            class="inline-flex min-h-12 items-center gap-2 rounded-2xl bg-blue-600 px-4 text-sm font-semibold text-white disabled:opacity-50"
                                            :aria-expanded="pickerFor === pickerKey(activeRoom, questionIndex)"
                                            @click="togglePicker(activeRoom, questionIndex)">
                                            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                <path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3"/>
                                            </svg>
                                            Добавить
                                        </button>
                                        <!-- A bottom sheet stays reachable on a short Telegram WebView,
                                             even when the last question is near the screen edge. -->
                                        <div v-if="pickerFor === pickerKey(activeRoom, questionIndex)"
                                            class="fixed inset-0 z-40 flex items-end justify-center bg-slate-950/50 p-0 sm:items-center sm:p-4"
                                            role="dialog" aria-modal="true" aria-label="Добавить фото или видео"
                                            @click.self="pickerFor = null" @keydown.esc="pickerFor = null">
                                            <div class="w-full max-w-lg space-y-2 rounded-t-3xl bg-white p-4 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl sm:rounded-3xl">
                                                <div class="mb-3 flex items-center justify-between gap-3">
                                                    <div>
                                                        <h4 class="text-base font-bold">Добавить фото или видео</h4>
                                                        <p class="mt-1 text-xs text-slate-500">Сначала проверим файл, затем сохраним на сервере</p>
                                                    </div>
                                                    <button type="button" class="grid size-11 shrink-0 place-items-center rounded-full bg-slate-100 text-lg text-slate-600"
                                                        aria-label="Закрыть выбор медиа" @click="pickerFor = null">✕</button>
                                                </div>
                                                <button type="button" class="flex min-h-14 w-full items-center rounded-2xl bg-blue-50 px-4 py-3 text-left font-semibold text-blue-900"
                                                    @click="openCamera(activeRoom, questionIndex, 'image')">Сделать фото</button>
                                                <button type="button" class="flex min-h-14 w-full items-center rounded-2xl bg-slate-50 px-4 py-3 text-left font-semibold text-slate-900"
                                                    @click="openCamera(activeRoom, questionIndex, 'video')">Снять видео</button>
                                                <label class="flex min-h-14 cursor-pointer items-center gap-3 rounded-2xl bg-slate-50 px-4 py-3 font-semibold text-slate-900">
                                                    <span aria-hidden="true">▦</span> Выбрать из галереи
                                                    <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm" class="sr-only"
                                                        :disabled="!canEdit" @change="addFiles($event, activeRoom, questionIndex)">
                                                </label>
                                                <p class="px-1 pt-2 text-xs text-slate-500">
                                                    В галерее можно выбрать несколько файлов.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="visibleMediaAt(activeRoom, questionIndex).length"
                                    class="mt-3 grid grid-cols-3 gap-2">
                                    <div v-for="item in visibleMediaAt(activeRoom, questionIndex)" :key="item.key"
                                        class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-slate-100">
                                        <button v-if="item.url && !mediaIsVideo(item)" type="button"
                                            class="relative block aspect-square w-full overflow-hidden bg-slate-200"
                                            :aria-label="'Открыть фото ' + (item.name || '')" @click="openGallery(activeRoom, questionIndex, item)">
                                            <img v-if="!item.source.previewFailed" :src="item.url" :alt="item.name || 'Фото'"
                                                class="size-full object-cover" loading="lazy" @error="item.source.previewFailed = true">
                                            <span v-else class="grid size-full place-items-center p-2 text-center text-xs font-semibold text-slate-600">
                                                {{ ['image/heic','image/heif'].includes(item.mime) ? 'Фото HEIC' : 'Предпросмотр недоступен' }}
                                            </span>
                                            <span v-if="item.kind === 'upload'"
                                                class="absolute inset-x-0 bottom-0 bg-slate-950/75 p-1.5 text-center text-[11px] font-semibold text-white">
                                                {{ item.status === 'error' ? 'Ошибка загрузки' : item.status === 'queued' ? (item.source.cached ? 'На устройстве' : 'В очереди') : item.status === 'attaching' ? 'Сохраняем на сервере' : 'Загружаем ' + item.progress + '%' }}
                                            </span>
                                            <span v-else class="absolute bottom-1 right-1 rounded-full bg-emerald-600 px-1.5 py-0.5 text-[10px] font-bold text-white">✓</span>
                                        </button>
                                        <button v-else-if="item.url && mediaIsVideo(item)"
                                            type="button" class="relative flex aspect-square w-full items-center justify-center overflow-hidden bg-slate-900"
                                            :aria-label="'Просмотреть видео ' + (item.name || '')"
                                            @click="openGallery(activeRoom, questionIndex, item)">
                                            <FocusVideoThumb :src="item.url" :name="item.name || 'Видео'" />
                                            <span class="relative z-10 grid size-12 place-items-center rounded-full bg-black/60 text-2xl text-white">▶</span>
                                            <span v-if="item.kind === 'upload'" class="absolute bottom-1 inset-x-1 rounded-lg bg-slate-950/75 py-1 text-[11px] font-semibold text-white">
                                                {{ item.status === 'error' ? 'Ошибка загрузки' : item.status === 'attaching' ? 'Сохраняем на сервере' : 'Загрузка ' + item.progress + '%' }}
                                            </span>
                                        </button>
                                        <div v-else class="grid aspect-square place-items-center bg-slate-200 p-2 text-center text-xs font-medium text-slate-600">
                                            {{ mediaIsVideo(item) ? 'Видео' : 'Фото' }} · требуется восстановление
                                        </div>
                                        <div v-if="item.kind === 'upload'" class="px-1.5 py-2 text-center text-[11px]">
                                            <div v-if="item.status === 'uploading'" class="mb-1.5 h-1 overflow-hidden rounded-full bg-slate-300">
                                                <div class="h-full bg-blue-600" :style="{ width: item.progress + '%' }"></div>
                                            </div>
                                            <p v-if="item.source.retryHint" class="mb-1 break-words text-amber-800" role="status">{{ item.source.retryHint }}</p>
                                            <p v-if="item.source.error" class="break-words text-red-700" role="alert">{{ item.source.error }}</p>
                                            <button v-if="item.status === 'error'" type="button" class="rounded-lg bg-blue-600 px-2 py-1.5 font-semibold text-white"
                                                @click="retryFile(item.source)">Повторить</button>
                                            <button v-if="['queued', 'uploading', 'error'].includes(item.status)" type="button"
                                                class="ml-1 rounded-lg px-2 py-1.5 font-semibold text-slate-700"
                                                @click="cancelFile(item.source)">Убрать</button>
                                        </div>
                                        <div v-else-if="item.kind === 'saved'" class="flex flex-wrap items-center justify-center gap-1 p-1.5 text-center">
                                            <label v-if="item.source.upload_id && canEdit && mediaCountAt(activeRoom, questionIndex) < 12"
                                                class="cursor-pointer rounded-lg bg-blue-50 px-2 py-1.5 text-[11px] font-semibold text-blue-700">
                                                Заменить
                                                <input type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif,video/mp4,video/quicktime,video/webm"
                                                    class="sr-only" :disabled="!canEdit"
                                                    :id="'focus-replace-' + item.source.upload_id" @change="beginSavedReplacement($event, activeRoom, questionIndex, item.source)">
                                            </label>
                                            <button v-if="testMode || item.source.upload_id" type="button"
                                                class="rounded-lg px-2 py-1.5 text-[11px] font-semibold text-slate-600"
                                                @click="removeSavedMedia(activeRoom, questionIndex, item.source)">Удалить</button>
                                            <span v-else class="text-[11px] text-emerald-700">Сохранено</span>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="mt-3 rounded-2xl bg-slate-50 px-4 py-4 text-center text-xs text-slate-500">
                                    Снимки появятся здесь сразу после подтверждения
                                </p>
                                <p v-if="!testMode && visibleMediaAt(activeRoom, questionIndex).some(item => item.kind === 'upload')"
                                    class="mt-2 text-xs text-slate-500">
                                    Можно перейти к следующему вопросу. Отправка контроля станет доступна после сохранения файлов.
                                </p>
                            </div>
                        </article>
                    </div>

                    <div class="sticky bottom-3 z-10 mt-4 grid grid-cols-2 gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
                        <button type="button" class="rounded-xl bg-slate-100 p-3 text-sm font-bold text-slate-700" @click="activeRoom === 0 ? stage = 'setup' : goRoom(activeRoom - 1)">{{ activeRoom ? 'Предыдущая комната' : 'К данным' }}</button>
                        <button type="button" class="rounded-xl bg-blue-600 p-3 text-sm font-bold text-white" @click="nextRoom">
                            {{ activeRoom === rooms.length - 1 ? 'К сводке' : 'Следующая комната' }}
                        </button>
                    </div>
                </template>

                <template v-else-if="stage === 'review'">
                    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Перед отправкой</p>
                        <h2 id="focus-review-heading" tabindex="-1" class="text-xl font-semibold">Проверьте данные контроля</h2>
                        <div class="space-y-2 rounded-2xl bg-slate-50 p-4 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <span class="text-slate-500">Квартира</span><strong class="max-w-[65%] text-right">{{ reviewApartmentName }}</strong>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <span class="text-slate-500">Сотрудник</span><strong class="max-w-[65%] text-right">{{ reviewCleanerName }}</strong>
                            </div>
                            <div v-if="setup.previous_cleaner" class="flex items-start justify-between gap-3">
                                <span class="text-slate-500">Предыдущий сотрудник</span><strong class="max-w-[65%] text-right">{{ setup.previous_cleaner }}</strong>
                            </div>
                            <div class="flex items-start justify-between gap-3">
                                <span class="text-slate-500">Уборка / проверка</span>
                                <strong class="text-right">{{ setup.cleaning_date || '—' }} / {{ setup.inspection_date || '—' }}</strong>
                            </div>
                        </div>
                        <button type="button" :disabled="!canEdit || submitting"
                            class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-800 disabled:opacity-50"
                            @click="editReviewSetup">Изменить данные</button>
                        <p v-if="setup.comment?.trim()" class="whitespace-pre-wrap break-words text-sm text-slate-700">{{ setup.comment }}</p>
                        <div class="grid grid-cols-3 gap-2 text-center text-xs">
                            <div class="rounded-xl bg-blue-50 p-3"><strong class="block text-lg text-blue-800">{{ completedRequired }}/{{ totalRequired }}</strong>Ответы</div>
                            <div class="rounded-xl bg-amber-50 p-3"><strong class="block text-lg text-amber-800">{{ reviewFindings.length }}</strong>Замечания</div>
                            <div class="rounded-xl bg-slate-100 p-3"><strong class="block text-lg text-slate-800">{{ reviewMediaStats.total }}</strong>Медиа</div>
                        </div>
                        <p class="text-xs text-slate-500">
                            Фото: {{ reviewMediaStats.photos }} · Видео: {{ reviewMediaStats.videos }} ·
                            {{ canSubmitMedia ? 'Все вложения подтверждены сервером' : 'Ожидается сохранение: ' + reviewMediaStats.waiting }}
                        </p>
                        <div v-if="reviewFindings.length" class="space-y-2">
                            <h3 class="text-sm font-bold">Замечания — проверьте перед отправкой</h3>
                            <button v-for="finding in reviewFindings" :key="finding.key" type="button"
                                class="flex min-h-14 w-full items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-left"
                                :aria-label="'Открыть замечание ' + finding.title"
                                @click="openReviewFinding(finding)">
                                <span class="min-w-0">
                                    <span class="block text-xs text-amber-800">{{ finding.roomTitle }} · {{ finding.answer }}</span>
                                    <strong class="mt-1 block text-sm text-slate-900">{{ finding.title }}</strong>
                                    <span class="text-xs text-slate-600">Вложений: {{ finding.mediaCount }}</span>
                                </span>
                                <span class="shrink-0 text-lg text-blue-700" aria-hidden="true">›</span>
                            </button>
                        </div>
                        <p v-else class="text-sm text-emerald-700">По выбранным ответам замечаний нет.</p>
                        <details class="rounded-2xl border border-slate-200 p-3">
                            <summary class="cursor-pointer text-sm font-bold">Все комнаты · {{ rooms.length }}</summary>
                            <div v-for="(room, index) in rooms" :key="index"
                                class="flex items-center justify-between border-b border-slate-100 py-3 last:border-b-0">
                                <span class="text-sm font-semibold">{{ room.title }}</span>
                                <span class="text-sm font-bold text-blue-700">{{ roomCompleted(index) }}/{{ roomRequired(index) }}</span>
                            </div>
                        </details>
                        <div v-if="!canSubmitMedia" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                            Остались файлы без подтверждения · {{ outstandingMedia }}. Отправка станет доступна после их сохранения или отмены.
                            <button type="button" class="mt-2 block min-h-10 rounded-lg bg-white px-3 font-bold text-blue-800" @click="jumpToPendingMedia()">К незагруженному вложению</button>
                        </div>
                        <p v-else-if="testMode" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                            Тест проходит настоящий серверный цикл, но не попадает в рабочие результаты, статистику, награды и Telegram.
                        </p>
                        <div v-if="submitError" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                            {{ submitError }}
                        </div>
                        <button type="button" :disabled="!canEdit || submitting || !canSubmitMedia" class="w-full rounded-xl bg-blue-600 p-4 text-sm font-bold text-white disabled:opacity-50" @click="submitControl">
                            {{ submitting ? 'Завершаем…' : testMode ? 'Завершить тест без статистики' : 'Отправить контроль' }}
                        </button>
                        <button type="button" :disabled="submitting" class="w-full rounded-xl bg-slate-100 p-3 text-sm font-bold disabled:opacity-50" @click="goRoom(rooms.length - 1)">Вернуться к вопросам</button>
                    </section>
                </template>
                <template v-else-if="stage === 'done'">
                    <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5" aria-live="polite">
                        <div class="text-3xl" aria-hidden="true">✓</div>
                        <h2 class="text-xl font-semibold text-emerald-800">{{ testMode ? 'Тест завершён' : 'Контроль завершён' }}</h2>
                        <p v-if="testMode" class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                            Тест завершён на сервере. Фото и видео сохранены отдельно, без рабочего контроля, статистики, наград и уведомлений Telegram.
                        </p>
                        <p v-else-if="finalResult?.media?.state === 'complete'" class="text-sm text-slate-600">Ответы и оценка сохранены. Фото и видео подтверждены сервером.</p>
                        <p v-else class="text-sm text-amber-900">Это ранее завершённый контроль. Материалы требуют восстановления.</p>
                        <div class="rounded-xl bg-slate-50 p-4">
                            <p class="text-sm text-slate-600">Оценка уборки</p>
                            <p class="mt-1 text-2xl font-semibold">{{ finalResult?.total_points }} / {{ finalResult?.max_points }} · {{ finalResult?.score_percent }}%</p>
                            <p class="mt-2 text-sm font-bold" :class="finalResult?.result_zone === 'green' ? 'text-emerald-700' : finalResult?.result_zone === 'red' ? 'text-red-700' : 'text-amber-700'">
                                {{ finalResult?.result_zone === 'green' ? 'Зелёная зона' : finalResult?.result_zone === 'red' ? 'Красная зона' : 'Жёлтая зона' }}
                            </p>
                            <p v-if="finalResult?.result_zone_reason" class="mt-1 text-xs text-slate-500">{{ finalResult.result_zone_reason }}</p>
                        </div>
                        <div v-if="!testMode" class="rounded-xl border border-slate-200 p-4">
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
                        <button v-if="testMode" type="button" class="block w-full rounded-xl bg-blue-600 p-4 text-center text-sm font-bold text-white" @click="startNewSandboxTest">Начать новый тест</button>
                        <button v-if="testMode" type="button" class="block w-full rounded-xl bg-slate-100 p-4 text-center text-sm font-bold text-slate-800" @click="exitTestMode">Вернуться к настоящим контролям</button>
                        <a v-else href="/checks" class="block w-full rounded-xl bg-slate-100 p-3 text-center text-sm font-bold text-slate-700">Все контроли</a>
                    </section>
                </template>
            </template>
        </div>
    </div>
</template>
