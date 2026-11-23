/**
 * Best-effort on-device recovery for unsynced form answers only.
 * Never persist file blobs, media paths, staff lists, or authorization data.
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

export function chooseLocalRecovery(record, server, now = Date.now()) {
    if (!record || record.scope !== server.scope
        || !Number.isFinite(record.saved_at) || record.saved_at > now + 60000
        || !record.run_id || !Number.isInteger(record.base_revision)
        || record.base_revision < 0 || !Array.isArray(record.answers)
        || !record.setup || typeof record.setup !== 'object') return 'none';

    if (now - record.saved_at > FOCUS_RECOVERY_MAX_AGE_MS) return 'expired';
    if (JSON.stringify(record.answers) === JSON.stringify(server.answers)
        && JSON.stringify(record.setup) === JSON.stringify(server.setup)) return 'synced';

    // A first, completely local run can continue only when the server has no active draft.
    if (record.draft_id == null && record.base_revision === 0
        && server.run?.draft_id == null && server.run?.revision === 0) return 'safe';

    // If a server draft exists, never silently apply edits based on an older revision.
    if (record.run_id === server.run?.id
        && record.draft_id === server.run?.draft_id
        && record.base_revision === server.run?.revision) return 'safe';

    return 'conflict';
}

const DB_NAME = 'tris-focus-v2-recovery';
const STORE = 'drafts';
let opening = null;

function openDatabase() {
    if (!globalThis.indexedDB) return Promise.reject(new Error('IndexedDB unavailable'));
    if (opening) return opening;
    opening = new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(STORE)) {
                request.result.createObjectStore(STORE, { keyPath: 'scope' });
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

async function transaction(mode, operation) {
    const db = await openDatabase();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const request = operation(tx.objectStore(STORE));
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
