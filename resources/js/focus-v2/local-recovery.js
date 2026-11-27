/**
 * Best-effort on-device recovery for unsynced form answers only.
 * Answer snapshots contain no file blobs. Photo backups are stored separately,
 * scoped by authenticated user/control/run, and removed on server confirmation.
 * The Laravel draft remains authoritative and its revision lock still applies.
 */
export const FOCUS_RECOVERY_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

export function recoveryScope(userId, controlId, schemaVersion) {
    if (!Number.isSafeInteger(Number(userId)) || Number(userId) < 1
        || !Number.isSafeInteger(Number(controlId)) || Number(controlId) < 1
        || !/^[a-f0-9]{64}$/i.test(String(schemaVersion))) {
        return null;
    }
    return ['focus-v2', String(userId), String(controlId), String(schemaVersion)].join(':');
}

// Keep the last acknowledged answer/setup content separate from pending edits.
export function recoveryContentStamp(setup, answers) {
    return JSON.stringify({ setup, answers }, (_, value) => {
        if (!value || typeof value !== 'object' || Array.isArray(value)) return value;
        return Object.fromEntries(Object.keys(value).sort().map(key => [key, value[key]]));
    });
}

export function chooseLocalRecovery(record, server, now = Date.now()) {
    if (!record || record.scope !== server.scope
        || !Number.isFinite(record.saved_at) || record.saved_at > now + 60000
        || !record.run_id || !Number.isInteger(record.base_revision)
        || record.base_revision < 0 || !Array.isArray(record.answers)
        || !record.setup || typeof record.setup !== 'object') return 'none';

    if (now - record.saved_at > FOCUS_RECOVERY_MAX_AGE_MS) return 'expired';
    const serverContent = recoveryContentStamp(server.setup, server.answers);
    if (recoveryContentStamp(record.setup, record.answers) === serverContent) return 'synced';

    // A first, completely local run can continue only when the server has no active draft.
    if (record.draft_id == null && record.base_revision === 0
        && server.run?.draft_id == null && server.run?.revision === 0) return 'safe';

    if (record.run_id === server.run?.id && record.draft_id === server.run?.draft_id) {
        if (record.base_revision === server.run?.revision) return 'safe';
        // A revision can advance because a photo was attached or deleted.
        // Only allow restoration if the current server answers and setup
        // still exactly match the known common base for this draft.
        if (record.draft_id != null && record.base_revision < server.run?.revision
            && record.base_content === serverContent) return 'safe';
    }

    return 'conflict';
}

const DB_NAME = 'tris-focus-v2-recovery';
const STORE = 'drafts';
// Existing IndexedDB v2 store name remains unchanged for backwards compatibility.
const PHOTO_STORE = 'photos';
const PHOTO_MAX_BYTES = 10 * 1024 * 1024;
export const VIDEO_RECOVERY_MAX_BYTES = 15 * 1024 * 1024;
export function canCacheFocusMedia(file) {
    const type = String(file?.type ?? '').toLowerCase();
    const limit = type.startsWith('image/') ? PHOTO_MAX_BYTES
        : type.startsWith('video/') ? VIDEO_RECOVERY_MAX_BYTES : 0;
    return limit > 0 && Number.isFinite(file?.size) && file.size > 0 && file.size <= limit;
}
let opening = null;

function openDatabase() {
    if (!globalThis.indexedDB) return Promise.reject(new Error('IndexedDB unavailable'));
    if (opening) return opening;
    opening = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 2);
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(STORE)) {
                request.result.createObjectStore(STORE, { keyPath: 'scope' });
            }
            if (!request.result.objectStoreNames.contains(PHOTO_STORE)) {
                const photos = request.result.createObjectStore(PHOTO_STORE, { keyPath: 'key' });
                photos.createIndex('by_scope', 'scope', { unique: false });
            }
        };
        request.onsuccess = () => {
            const db = request.result;
            db.onversionchange = () => {
                db.close();
                opening = null;
            };
            resolve(db);
        };
        request.onerror = () => reject(request.error || new Error('IndexedDB open failed'));
        request.onblocked = () => reject(new Error('IndexedDB is blocked'));
    }).catch(error => {
        opening = null;
        throw error;
    });
    return opening;
}

async function transaction(mode, operation, storeName = STORE) {
    const db = await openDatabase();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(storeName, mode);
        const request = operation(tx.objectStore(storeName));
        let value;
        request.onsuccess = () => { value = request.result; };
        tx.oncomplete = () => resolve(value);
        tx.onerror = () => reject(tx.error || new Error('IndexedDB transaction failed'));
        tx.onabort = () => reject(tx.error || new Error('IndexedDB transaction aborted'));
    });
}

export async function readLocalRecovery(scope) {
    return transaction('readonly', store => store.get(scope));
}

export async function writeLocalRecovery(record) {
    return transaction('readwrite', store => store.put(record));
}

export async function deleteLocalRecovery(scope) {
    return transaction('readwrite', store => store.delete(scope));
}

/**
 * Coalesce rapid typing: an in-flight write is followed by only the newest
 * snapshot or delete. The order guarantees that a server acknowledgement's
 * delete cannot be overwritten by an older pending write.
 */
export function createLocalRecoveryQueue(writer) {
    let pending = null;
    let active = null;
    return function enqueue(operation) {
        pending = operation;
        if (active) return active;
        active = (async () => {
            while (pending !== null) {
                const next = pending;
                pending = null;
                await writer(next);
            }
        })().finally(() => { active = null; });
        return active;
    };
}


/**
 * Best-effort caching for confirmed photos and short videos (up to 15 MiB).
 * Large videos are *never* cached, avoiding quotas and stalled WebViews.
 * A File/Blob is structured-cloned into IndexedDB, not base64/localStorage.
 *
 * Telegram WebView may evict its origin storage; callers MUST treat errors
 * here as a warning, not as proof that an unsent photo is safe.
 */
export async function cacheFocusPhoto({ scope, uploadId, runId, file, room, question, beforeDraft = false }) {
    if (!scope || !/^[0-9a-f-]{36}$/i.test(uploadId) || !runId
        || !canCacheFocusMedia(file)
        || !Number.isInteger(room) || room < 0
        || !Number.isInteger(question) || question < 0) {
        throw new Error('Невозможно сохранить локальную копию этого медиафайла.');
    }
    const record = {
        key: scope + ':' + uploadId, scope, upload_id: uploadId, run_id: runId,
        ...(beforeDraft ? { before_draft: true } : {}),
        room, question, name: String(file.name || 'photo.jpg'),
        mime: file.type, size: file.size, saved_at: Date.now(),
        // Safari/WebKit can reject structured-cloning File objects in
        // IndexedDB even when a plain Blob is supported. Store a slice of
        // the same bytes and preserve name/MIME separately for restoration.
        blob: file.slice(0, file.size, file.type),
    };
    await transaction('readwrite', store => store.put(record), PHOTO_STORE);
    return record;
}

export async function readCachedFocusPhotos(scope, now = Date.now()) {
    if (!scope) return [];
    const records = await transaction('readonly', store =>
        store.index('by_scope').getAll(scope), PHOTO_STORE);
    const valid = [];
    for (const record of records ?? []) {
        if (!record || !record.blob || record.blob.size !== record.size
            || !Number.isFinite(record.saved_at)
            || record.saved_at > now + 60000
            || now - record.saved_at > FOCUS_RECOVERY_MAX_AGE_MS) {
            if (record?.upload_id) await forgetCachedFocusPhoto(scope, record.upload_id);
        } else {
            valid.push(record);
        }
    }
    return valid;
}

export async function forgetCachedFocusPhoto(scope, uploadId) {
    if (!scope || !uploadId) return;
    await transaction('readwrite', store => store.delete(scope + ':' + uploadId), PHOTO_STORE);
}
