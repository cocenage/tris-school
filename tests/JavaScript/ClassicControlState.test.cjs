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

function helpers(overrides = {}) {
    const window = { setTimeout, clearTimeout, ...overrides };
    const script = source.slice(source.indexOf('window.controlCompressPhoto ='), source.indexOf('</script>', source.indexOf('window.controlCompressPhoto =')));
    vm.runInNewContext(script, { window, File, Blob, URL, Image: overrides.Image, document: overrides.document, console });
    return window;
}

test('a compressible photo above the transport limit enters preparation instead of being discarded', async () => {
    let uploads = 0;
    const state = form(async () => { uploads++; return 'saved'; });
    state.startMediaUpload('0.0', {}, 0, 0, [new File([new Uint8Array(3 * 1024 * 1024)], 'large.png', { type: 'image/png' })]);
    await tick();
    assert.equal(uploads, 1);
    assert.equal(state.mediaUploads['0.0'][0].state, 'saved');
    assert.equal(state.pendingUploads, 0);
});

test('compression keeps the original when JPEG encoding would make it larger', async () => {
    const file = new File([new Uint8Array(1024 * 1024)], 'photo.png', { type: 'image/png' });
    const window = helpers({
        Image: class { naturalWidth = 1000; naturalHeight = 800; set src(value) { this.onload(); } },
        document: { createElement: () => ({ getContext: () => ({ drawImage() {} }), toBlob: callback => callback(new Blob([new Uint8Array(file.size + 1)])) }) },
    });
    assert.equal(await window.controlCompressPhoto(file), file);
});

test('only the prepared file is checked against the real transport limit', async () => {
    const original = new File([new Uint8Array(3 * 1024 * 1024)], 'large.png', { type: 'image/png' });
    const prepared = new File(['prepared'], 'photo.jpg', { type: 'image/jpeg' });
    const window = helpers();
    window.controlCompressPhoto = async () => prepared;
    let received;
    const livewire = { upload(property, file, success) { received = file; success(); }, async call() { return 'saved'; } };
    assert.equal(await window.controlUploadCompressedPhotos(livewire, 'photoUploads.0.0.test', 0, 0, original, () => {}, new AbortController(), () => {}, 'id', 'key', 2 * 1024 * 1024), 'saved');
    assert.equal(received, prepared);
    window.controlCompressPhoto = async file => file;
    await assert.rejects(window.controlUploadCompressedPhotos(livewire, 'photoUploads.0.0.test', 0, 0, original, () => {}, new AbortController(), () => {}, 'id', 'key', 2 * 1024 * 1024), /лимит загрузки 2 МБ/);
});

test('file input events do not schedule a redundant answer autosave', () => {
    const state = form(null);
    state.handleFormChange({ target: { type: 'file' } });
    assert.equal(state.saveRevision, 0);
    state.handleFormChange({ target: { type: 'text' } });
    assert.equal(state.saveRevision, 1);
});

test('a saving item cannot disappear from the queue through a local remove', async () => {
    let complete;
    const state = form((wire, property, room, question, file, progress, controller, saving) => {
        saving();
        return new Promise(resolve => { complete = resolve; });
    });
    state.startMediaUpload('0.0', {}, 0, 0, [new File(['one'], 'one.jpg')]);
    await tick();
    const item = state.mediaUploads['0.0'][0];
    state.removeMediaUpload('0.0', 0, 0, item.id);
    assert.equal(state.mediaUploads['0.0'][0], item);
    assert.equal(state.pendingUploads, 1);
    complete('saved');
    await tick();
    assert.equal(item.state, 'saved');
    assert.equal(state.pendingUploads, 0);
});

test('cancelling preparation drains the queue and keeps the cancelled File available for retry', async () => {
    const state = form((wire, property, room, question, file, progress, controller) => new Promise((resolve, reject) => {
        controller.signal.addEventListener('abort', () => reject(new Error('Upload cancelled')));
    }));
    const file = new File(['one'], 'one.jpg');
    state.startMediaUpload('0.0', {}, 0, 0, [file]);
    await tick();
    const item = state.mediaUploads['0.0'][0];
    assert.equal(item.state, 'preparing');
    state.cancelMediaUpload('0.0', 0, 0, item.id);
    await tick();
    assert.equal(item.state, 'cancelled');
    assert.equal(item.file, file);
    assert.equal(state.pendingUploads, 0);
    assert.equal(state.mediaProcessing, false);
});

test('a timed out upload is cancelled and a late callback cannot confirm a file', async () => {
    const timers = [];
    const window = helpers({ setTimeout(callback) { timers.push(callback); return timers.length; }, clearTimeout() {} });
    window.controlCompressPhoto = async file => file;
    let success;
    let cancelled;
    let cancellations = 0;
    let confirmations = 0;
    const livewire = { upload(property, file, callback, error, progress, cancel) { success = callback; cancelled = cancel; }, cancelUpload() { cancellations++; cancelled(); }, async call() { confirmations++; return 'saved'; } };
    const pending = window.controlUploadCompressedPhotos(livewire, 'photoUploads.0.0.key', 0, 0, new File(['one'], 'one.jpg'), () => {}, new AbortController(), () => {});
    await tick();
    timers[0]();
    await assert.rejects(pending, /Upload timed out/);
    success();
    await tick();
    assert.equal(cancellations, 1);
    assert.equal(confirmations, 0);
});

test('review and Livewire navigation do not bypass an unfinished file upload', async () => {
    let actions = 0;
    const state = form(null, { async continueForm() { actions++; return 'saved'; } });
    state.pendingUploads = 1;
    await state.continueAfterSave();
    assert.equal(actions, 0);
    state.init();
    let prevented = false;
    state.navigationHandler({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    state.destroy();
});

test('a non-Error network rejection cannot strand later files or pendingUploads', async () => {
    const state = form((wire, property, room, question, file) => file.name === 'bad.jpg' ? Promise.reject(null) : Promise.resolve('saved'));
    state.startMediaUpload('0.0', {}, 0, 0, [new File(['bad'], 'bad.jpg'), new File(['good'], 'good.jpg')]);
    for (let i = 0; i < 5; i++) await tick();
    assert.deepEqual(Array.from(state.mediaUploads['0.0'], item => item.state), ['error', 'saved']);
    assert.equal(state.pendingUploads, 0);
});

test('a lost save acknowledgement is reconciled before retrying bytes or finalizing', async () => {
    let uploads = 0;
    let submits = 0;
    const wire = { async finishPhotoUpload() { return 'saved'; }, async confirmSubmit() { submits++; } };
    const state = form(async (wire, property, room, question, file, progress, controller, saving) => {
        uploads++;
        saving();
        throw new Error('Connection lost after save');
    }, wire);
    state.startMediaUpload('0.0', {}, 0, 0, [new File(['one'], 'one.jpg')]);
    await tick();
    const item = state.mediaUploads['0.0'][0];
    assert.equal(item.state, 'error');
    assert.equal(state.hasUnconfirmedMedia, true);
    state.removeMediaUpload('0.0', 0, 0, item.id);
    assert.equal(state.mediaUploads['0.0'][0], item);
    await state.confirmSubmitAfterSave();
    assert.equal(submits, 0);
    state.retryMediaUpload('0.0', 0, 0, item.id);
    await tick();
    assert.equal(item.state, 'saved');
    assert.equal(item.file, null);
    assert.equal(uploads, 1);
    assert.equal(state.hasUnconfirmedMedia, false);
    assert.equal(state.pendingUploads, 0);
});

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
