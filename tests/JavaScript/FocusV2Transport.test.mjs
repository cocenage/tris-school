import test from 'node:test';
import assert from 'node:assert/strict';
import {
    FOCUS_CHUNK_BYTES, allowedFocusFile, transferFocusFile,
} from '../../resources/js/focus-v2/media-upload.js';

const reply = (body, status = 200) => ({
    ok: status >= 200 && status < 300,
    status,
    async json() { return body; },
});

const fileOfSize = (size, name = 'evidence.mp4', type = 'video/mp4') => ({
    size, name, type,
    slice(start, end) { return { size: end - start }; },
});

const draft = {
    control_id: 4, draft_id: 15, run_id: '00000000-0000-4000-8000-000000000001',
    schema_version: 'a'.repeat(64),
};
const urls = {
    chunk_base: '/upload/chunks',
    complete: '/upload/complete',
    attach: '/upload/attach',
};
function transfer(file, extra = {}) {
    return transferFocusFile({
        file, startUrl: '/upload/start', uploadId: 'upload-17', draft,
        room: 1, question: 0, csrf: 'test-csrf',
        signal: new AbortController().signal,
        onProgress() {},
        ...extra,
    });
}

test('resume skips chunks acknowledged by server following an interrupted transfer', async () => {
    const original = globalThis.fetch;
    const file = fileOfSize(FOCUS_CHUNK_BYTES * 6 + 13);
    const received = new Set();
    const sent = [];
    let disconnect = true;
    globalThis.fetch = async (url, options) => {
        const path = String(url);
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.headers['X-CSRF-TOKEN'], 'test-csrf');
        if (path === '/upload/start') {
            const data = JSON.parse(options.body);
            assert.equal(data.upload_id, 'upload-17');
            assert.equal(data.size, file.size);
            return reply({ status: 'pending', received: [...received], chunk_bytes: FOCUS_CHUNK_BYTES,
                total_chunks: 7, urls });
        }
        if (path.startsWith('/upload/chunks/')) {
            const index = Number(path.split('/').pop());
            assert.equal(options.method, 'PUT');
            assert.equal(options.body.size, Math.min(FOCUS_CHUNK_BYTES, file.size - index * FOCUS_CHUNK_BYTES));
            sent.push(index);
            if (index === 3 && disconnect) {
                disconnect = false;
                throw new TypeError('Network disconnected');
            }
            received.add(index);
            return reply({});
        }
        if (path === '/upload/complete') {
            assert.equal(received.size, 7);
            return reply({ status: 'ready', urls });
        }
        throw new Error('Unexpected URL: ' + path);
    };
    try {
        const progress = [];
        await assert.rejects(transfer(file, { onProgress: p => progress.push(p) }), /Network disconnected/);
        assert.deepEqual([...received], [0, 1, 2]);
        const result = await transfer(file, { onProgress: p => progress.push(p) });
        assert.equal(result.status, 'ready');
        assert.deepEqual(sent, [0, 1, 2, 3, 3, 4, 5, 6]);
        assert.equal(progress.at(-1), 100);
    } finally {
        globalThis.fetch = original;
    }
});

test('supports a 100 MiB video in 512 KiB requests without sending a 100 MiB POST', async () => {
    const original = globalThis.fetch;
    const file = fileOfSize(100 * 1024 * 1024);
    let parts = 0;
    let maxBody = 0;
    globalThis.fetch = async (url, options) => {
        if (url === '/upload/start') {
            assert.equal(options.method, 'POST');
            assert.equal(JSON.parse(options.body).size, file.size);
            return reply({ status: 'pending', received: [], chunk_bytes: FOCUS_CHUNK_BYTES,
                total_chunks: 200, urls });
        }
        if (String(url).startsWith('/upload/chunks/')) {
            parts++;
            maxBody = Math.max(maxBody, options.body.size);
            return reply({});
        }
        if (url === '/upload/complete') return reply({ status: 'ready', urls });
        throw new Error('Unexpected request');
    };
    try {
        assert.equal(allowedFocusFile(file), '');
        assert.equal((await transfer(file)).status, 'ready');
        assert.equal(parts, 200);
        assert.equal(maxBody, 512 * 1024);
        assert.match(allowedFocusFile(fileOfSize(file.size + 1)), /100 МБ/);
    } finally {
        globalThis.fetch = original;
    }
});

test('retries out-of-order receipts and never uploads already confirmed chunks', async () => {
    const original = globalThis.fetch;
    const file = fileOfSize(FOCUS_CHUNK_BYTES * 2 + 5);
    const sent = [];
    globalThis.fetch = async (url, options) => {
        if (url === '/upload/start') return reply({
            status: 'pending', received: [0, 2], chunk_bytes: FOCUS_CHUNK_BYTES,
            total_chunks: 3, urls,
        });
        if (String(url).startsWith('/upload/chunks/')) {
            sent.push(Number(String(url).split('/').pop()));
            return reply({});
        }
        if (url === '/upload/complete') return reply({ status: 'ready', urls });
        throw new Error('Unexpected request');
    };
    try {
        await transfer(file);
        assert.deepEqual(sent, [1]);
    } finally {
        globalThis.fetch = original;
    }
});

test('rejects inconsistent upload parameters and a failed final assembly', async () => {
    const original = globalThis.fetch;
    const file = fileOfSize(FOCUS_CHUNK_BYTES);
    try {
        globalThis.fetch = async () => reply({
            status: 'pending', received: [], chunk_bytes: FOCUS_CHUNK_BYTES,
            total_chunks: 99, urls,
        });
        await assert.rejects(transfer(file), /некорректные параметры/);
        globalThis.fetch = async (url) => {
            if (url === '/upload/start') return reply({
                status: 'pending', received: [0], chunk_bytes: FOCUS_CHUNK_BYTES,
                total_chunks: 1, urls,
            });
            if (url === '/upload/complete') return reply({ status: 'error', urls });
            throw new Error('Unexpected request');
        };
        await assert.rejects(transfer(file), /не подтвердил готовность/);
    } finally {
        globalThis.fetch = original;
    }
});
