import test from 'node:test';
import assert from 'node:assert/strict';
import { File } from 'node:buffer';
import {
    FOCUS_RECOVERY_MAX_AGE_MS, VIDEO_RECOVERY_MAX_BYTES, cacheFocusPhoto, forgetCachedFocusPhoto,
    readCachedFocusPhotos, recoveryScope,
} from '../../resources/js/focus-v2/local-recovery.js';

function fakeIndexedDatabase() {
    const tables = new Map();
    function requestFor(tx, valueFactory) {
        const request = { result: undefined };
        queueMicrotask(() => {
            try {
                request.result = valueFactory();
                request.onsuccess?.();
                tx.oncomplete?.();
            } catch (error) {
                tx.error = error;
                tx.onerror?.();
            }
        });
        return request;
    }
    const db = {
        objectStoreNames: { contains: name => tables.has(name) },
        createObjectStore(name) {
            const table = new Map();
            tables.set(name, table);
            return { createIndex() {} };
        },
        transaction(name) {
            const table = tables.get(name);
            const tx = { oncomplete: null, onerror: null, onabort: null };
            tx.objectStore = () => ({
                put: record => requestFor(tx, () => { table.set(record.key || record.scope, record); return record.key; }),
                delete: key => requestFor(tx, () => { table.delete(key); return undefined; }),
                get: key => requestFor(tx, () => table.get(key)),
                index: () => ({
                    getAll: scope => requestFor(tx, () => Array.from(table.values())
                        .filter(item => item.scope === scope)),
                }),
            });
            return tx;
        },
    };
    return {
        open() {
            const request = { result: db };
            queueMicrotask(() => {
                request.onupgradeneeded?.();
                request.onsuccess?.();
            });
            return request;
        },
    };
}

globalThis.indexedDB = fakeIndexedDatabase();
const scopeA = recoveryScope(4, 7, 'a'.repeat(64));
const scopeB = recoveryScope(5, 7, 'a'.repeat(64));
const uploadA = 'e4d45b6d-57a6-4f39-8800-f10f362f3cdf';
const uploadB = 'f0cefe25-472d-46a3-b5cf-641231385db5';
const file = new File([new Uint8Array([137, 80, 78, 71, 13, 10])], 'photo.png', { type: 'image/png' });

test('backs up one selected photo by user/control/run without leaking another supervisor photos', async () => {
    await cacheFocusPhoto({ scope: scopeA, uploadId: uploadA, runId: 'run-a',
        file, room: 0, question: 1 });
    await cacheFocusPhoto({ scope: scopeB, uploadId: uploadB, runId: 'run-b',
        file, room: 1, question: 0 });
    const own = await readCachedFocusPhotos(scopeA);
    assert.equal(own.length, 1);
    assert.equal(own[0].run_id, 'run-a');
    assert.equal(own[0].room, 0);
    assert.equal(own[0].question, 1);
    assert.equal(own[0].blob.size, file.size);
    assert.equal((await readCachedFocusPhotos(scopeB)).length, 1);

    await forgetCachedFocusPhoto(scopeA, uploadA);
    assert.deepEqual(await readCachedFocusPhotos(scopeA), []);
    assert.equal((await readCachedFocusPhotos(scopeB)).length, 1);
});

test('backs up short videos but never keeps 100 MiB movies in IndexedDB', async () => {
    const shortVideo = new File(['bytes'], 'clip.mp4', { type: 'video/mp4' });
    await cacheFocusPhoto({
        scope: scopeA, uploadId: uploadA, runId: 'run-a',
        file: shortVideo, room: 0, question: 0,
    });
    const [saved] = await readCachedFocusPhotos(scopeA);
    assert.equal(saved.mime, 'video/mp4');
    assert.equal(saved.blob.size, shortVideo.size);
    assert.equal(saved.name, 'clip.mp4');
    await forgetCachedFocusPhoto(scopeA, uploadA);

    // Rejected on size before attempting to structured-clone a huge blob.
    await assert.rejects(cacheFocusPhoto({
        scope: scopeA, uploadId: uploadA, runId: 'run-a',
        file: { size: VIDEO_RECOVERY_MAX_BYTES + 1, type: 'video/mp4', name: 'large.mp4' },
        room: 0, question: 0,
    }));
});

test('expires stale photo backups after the configured retention', async () => {
    await cacheFocusPhoto({ scope: scopeA, uploadId: uploadA, runId: 'run-a',
        file, room: 0, question: 0 });
    const [saved] = await readCachedFocusPhotos(scopeA);
    const expired = await readCachedFocusPhotos(scopeA, saved.saved_at + FOCUS_RECOVERY_MAX_AGE_MS + 1);
    assert.deepEqual(expired, []);
    assert.deepEqual(await readCachedFocusPhotos(scopeA), []);
});
