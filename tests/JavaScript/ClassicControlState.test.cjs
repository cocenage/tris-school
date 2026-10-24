const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const { randomUUID } = require('node:crypto');

const source = readFileSync('resources/views/components/forms/⚡page-control.blade.php', 'utf8');
const expression = source.match(/x-data="(\{\s+timer: null,[\s\S]*?)"\s+x-on:control-reset-request/)[1]
    .replace('@js($openRoomIndex)', '0')
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
