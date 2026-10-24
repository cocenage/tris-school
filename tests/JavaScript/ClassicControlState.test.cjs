const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { randomUUID } = require('node:crypto');
const { File } = require('node:buffer');

const source = readFileSync('resources/views/components/forms/⚡page-control.blade.php', 'utf8');
const expression = source.match(/x-data="(\{\s+timer: null,[\s\S]*?)"\s+x-on:control-reset-request/)[1]
    .replace('@js($openRoomIndex)', '0')
    .replace('@js($this->effectiveMediaUploadLimitBytes())', '2097152')
    .replace("@entangle('hasUnsavedChanges').live", 'false')
    .replace('@js($draftState)', "'idle'");
const tick = () => new Promise(resolve => setImmediate(resolve));

function form(upload, wire = {}) {
    return vm.runInNewContext(`(${expression})`, {
        window: { controlUploadCompressedPhotos: upload, dispatchEvent() {}, addEventListener() {}, removeEventListener() {} },
        document: { addEventListener() {}, removeEventListener() {}, visibilityState: 'visible' },
        $wire: wire, AbortController, crypto: { randomUUID }, CustomEvent: class {},
        setTimeout: () => 1, clearTimeout() {},
    });
}

test('media across different questions uses one upload slot', async () => {
    let active = 0;
    let peak = 0;
    const finish = [];
    const state = form(() => new Promise(resolve => {
        peak = Math.max(peak, ++active);
        finish.push(() => { active--; resolve('saved'); });
    }));
    state.startMediaUpload('0.0', {}, 0, 0, [{ name: 'one.jpg' }]);
    state.startMediaUpload('1.0', {}, 1, 0, [{ name: 'two.mp4' }]);
    await tick();
    assert.equal(peak, 1);
    finish.shift()();
    await tick();
    finish.shift()();
    await tick();
    assert.equal(state.pendingUploads, 0);
    assert.equal(state.mediaUploads['0.0'][0].state, 'saved');
    assert.equal(state.mediaUploads['1.0'][0].state, 'saved');
});

test('failed files do not discard a successful file or block final submission', async () => {
    const calls = [];
    const wire = { successSheetOpen: false, async confirmSubmit() { this.successSheetOpen = true; } };
    const state = form(async (wire, property, room, question, file) => {
        calls.push(file.name);
        if (file.name !== 'good.jpg') throw new Error('Upload failed');
        return 'saved';
    }, wire);
    state.startMediaUpload('0.0', {}, 0, 0, [{name:'good.jpg'}, {name:'bad.jpg'}, {name:'bad.mp4'}]);
    await tick();
    assert.deepEqual(Array.from(state.mediaUploads['0.0'], f => f.state), ['saved','error','error']);
    assert.equal(state.pendingUploads, 0);
    state.retryMediaUpload('0.0', 0, 0, state.mediaUploads['0.0'][2].id);
    await tick();
    assert.equal(calls.filter(name => name === 'good.jpg').length, 1);
    await state.confirmSubmitAfterSave();
    assert.equal(state.clientDraftState, 'submitted');
});

test('answers made during a slow save are flushed before final submission', async () => {
    let resolveSave;
    let saves = 0;
    const wire = { successSheetOpen: false,
        saveDraftAuto() { return ++saves === 1 ? new Promise(resolve => resolveSave = resolve) : Promise.resolve('saved'); },
        async confirmSubmit() { assert.equal(saves, 2); this.successSheetOpen = true; },
    };
    const state = form(null, wire);
    state.save();
    const pending = state.flushAutosave();
    for (let i = 0; i < 10; i++) state.save();
    resolveSave('saved');
    await pending;
    assert.equal(state.clientDraftState, 'dirty');
    assert.equal(state.persistedSaveRevision, 1);
    await state.confirmSubmitAfterSave();
    assert.equal(state.persistedSaveRevision, 11);
    assert.equal(state.clientDraftState, 'submitted');
});

test('room navigation stays local and server failure never becomes saved', async () => {
    const state = form(null, { async saveDraftAuto() { throw new Error('Network unavailable'); } });
    state.$nextTick = callback => callback();
    state.toggleRoomLocally(1);
    assert.equal(state.openRoomIndex, 1);
    state.save();
    assert.equal(await state.flushAutosave(), false);
    assert.equal(state.clientDraftState, 'error');
    assert.equal(state.persistedSaveRevision, 0);
});

test('a fast cancel and retry cannot strand the pending count', async () => {
    let calls = 0;
    const state = form((wire, property, room, question, file, progress, controller) => {
        calls++;
        if (calls > 1) return Promise.resolve('saved');
        return new Promise((resolve, reject) => {
            controller.signal.addEventListener('abort', () => reject(new Error('Upload cancelled')));
        });
    });
    state.startMediaUpload('0.0', {}, 0, 0, [{ name: 'one.jpg' }]);
    await tick();
    const id = state.mediaUploads['0.0'][0].id;
    state.cancelMediaUpload('0.0', 0, 0, id);
    state.retryMediaUpload('0.0', 0, 0, id);
    await tick();
    assert.equal(state.pendingUploads, 0);
    assert.equal(state.mediaUploads['0.0'][0].state, 'cancelled');
    state.retryMediaUpload('0.0', 0, 0, id);
    await tick();
    assert.equal(state.pendingUploads, 0);
    assert.equal(state.mediaUploads['0.0'][0].state, 'saved');
});

test('a successful control submission clears local media before the next apartment control', async () => {
    const wire = { successSheetOpen: false, async confirmSubmit() { this.successSheetOpen = true; } };
    const state = form(async () => 'saved', wire);
    state.mediaUploads['0.0'] = [{ id: 1, uploadId: 'old-control-file', name: 'Bramante A.jpg', state: 'saved', file: null }];

    await state.confirmSubmitAfterSave();

    assert.deepEqual(Object.keys(state.mediaUploads), []);
    assert.equal(state.pendingUploads, 0);
    assert.equal(state.clientDraftState, 'submitted');
});

test('videos upload one at a time and a rejected second video keeps its reason', async () => {
    const calls = [];
    const state = form(async (wire, property, room, question, file) => {
        calls.push(file.name);
        if (file.name === 'bad.webm') throw new Error('Этот файл превышает лимит PHP 2 МБ');
        return 'saved';
    });

    state.startMediaUpload('0.0', {}, 0, 0, [{ name: 'one.webm', type: 'video/webm', size: 1000 }, { name: 'bad.webm', type: 'video/webm', size: 1000 }]);
    for (let i = 0; i < 8; i++) await tick();

    assert.deepEqual(calls, ['one.webm', 'bad.webm']);
    assert.deepEqual(Array.from(state.mediaUploads['0.0'], file => file.state), ['saved', 'error']);
    assert.equal(state.mediaUploads['0.0'][1].error, 'Этот файл превышает лимит PHP 2 МБ');
    assert.equal(state.pendingUploads, 0);
});

test('a file over the effective server limit is rejected before temporary upload', async () => {
    let calls = 0;
    const state = form(async () => { calls++; return 'saved'; });
    state.maxMediaUploadBytes = 2 * 1024 * 1024;

    state.startMediaUpload('0.0', {}, 0, 0, [{ name: 'large.mp4', type: 'video/mp4', size: 3 * 1024 * 1024 }]);
    await tick();

    assert.equal(calls, 0);
    assert.equal(state.pendingUploads, 0);
    assert.equal(state.mediaUploads['0.0'][0].state, 'error');
    assert.match(state.mediaUploads['0.0'][0].error, /лимит загрузки 2 МБ/);
});

test('100 percent transfer remains uploading until the server confirms the save', async () => {
    let finishSave;
    const state = form((wire, property, room, question, file, progress) => {
        progress(100);
        return new Promise(resolve => { finishSave = resolve; });
    });

    state.startMediaUpload('0.0', {}, 0, 0, [{ name: 'clip.webm', type: 'video/webm', size: 1000 }]);
    await tick();
    assert.equal(state.mediaUploads['0.0'][0].progress, 100);
    assert.notEqual(state.mediaUploads['0.0'][0].state, 'saved');

    finishSave('saved');
    await tick();
    assert.equal(state.mediaUploads['0.0'][0].state, 'saved');
});

test('three selected photos and two added during upload keep all five File objects in one queue', async () => {
    const files = Array.from({ length: 5 }, (_, i) => new File([`photo-${i}`], `photo-${i}.jpg`, { type: 'image/jpeg' }));
    const started = [];
    const finish = [];
    let saves = 0;
    const state = form((wire, property, room, question, file) => {
        started.push(file);
        return new Promise(resolve => finish.push(() => resolve('saved')));
    }, { async saveDraftAuto() { saves++; return 'saved'; } });

    state.startMediaUpload('0.0', {}, 0, 0, files.slice(0, 3));
    await tick();
    state.startMediaUpload('0.0', {}, 0, 0, files.slice(3));
    state.save();
    assert.equal(await state.flushAutosave(), false);
    assert.equal(saves, 0);
    assert.equal(started.length, 1);
    assert.equal(state.pendingUploads, 5);
    assert.deepEqual(Array.from(state.mediaUploads['0.0'], item => item.file), files);

    for (let i = 0; i < files.length; i++) {
        assert.equal(started[i], files[i]);
        assert.equal(await started[i].text(), `photo-${i}`);
        finish.shift()();
        await tick();
    }

    assert.deepEqual(started, files);
    assert.equal(state.pendingUploads, 0);
    assert.equal(state.mediaProcessing, false);
    assert.deepEqual(Array.from(state.mediaUploads['0.0'], item => item.state), Array(5).fill('saved'));
    assert.equal(await state.flushAutosave(), true);
    assert.equal(saves, 1);
});

test('cancelling a queued file and retrying retains its File and upload identity without repeating saved files', async () => {
    const files = [new File(['first'], 'first.jpg'), new File(['second'], 'second.jpg')];
    const started = [];
    const finish = [];
    const state = form((wire, property, room, question, file, progress, controller, saving, uploadId, uploadKey) => {
        started.push({ file, uploadId, uploadKey });
        return new Promise(resolve => finish.push(() => resolve('saved')));
    });
    state.startMediaUpload('0.0', {}, 0, 0, files);
    await tick();
    const second = state.mediaUploads['0.0'][1];
    const identity = second.uploadId;
    state.cancelMediaUpload('0.0', 0, 0, second.id);
    assert.equal(second.state, 'cancelled');
    assert.equal(state.pendingUploads, 1);
    state.retryMediaUpload('0.0', 0, 0, second.id);
    assert.equal(second.file, files[1]);
    assert.equal(state.pendingUploads, 2);
    finish.shift()();
    await tick();
    finish.shift()();
    await tick();
    assert.deepEqual(started.map(item => item.file), files);
    assert.equal(started[1].uploadId, identity);
    assert.equal(started[1].uploadKey, `${identity}_1`);
    assert.equal(state.pendingUploads, 0);
    assert.equal(second.state, 'saved');
});
