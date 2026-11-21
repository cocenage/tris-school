const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

const source = readFileSync('resources/views/components/forms/⚡page-control-focus.blade.php', 'utf8');
const expression = source.match(/x-data="(\{\s+activeRoomIndex:[\s\S]*?)"\s+x-on:focus-question-selected/)[1]
    .replace(/@entangle\([^)]*\)/g, 'null')
    .replace('@js($this->questionCompletion)', '[[false, false]]')
    .replace('@js($this->requiredQuestionsByRoom)', '[[0, 1]]')
    .replace('@js($draftState)', "'idle'")
    .replace('@js($draftError)', "''")
    .replace('@js(FocusMediaLimits::transportBytes())', '104857600')
    .replace(/\{\{.*?\}\}/g, '2');
const form = () => vm.runInNewContext(`(${expression})`, { window: { setTimeout: () => 1, clearTimeout() {} } });

test('answer interactions update room progress before the next server reply', () => {
    const state = form();
    assert.match(source, /selected = @js\(\$value\); updateQuestionCompletion\(/);
    assert.match(source, /x-on:input="updateQuestionCompletion\([^"]+\); scheduleAutosave\(900\)"/);
    state.updateQuestionCompletion(0, 0, 'yes', '');
    assert.equal(state.roomAnsweredCount(0), 1);
    assert.equal(state.roomProgress(0), 'partial');
    state.updateQuestionCompletion(0, 1, '', 'Comment');
    assert.equal(state.roomAnsweredCount(0), 2);
    assert.equal(state.roomProgress(0), 'complete');
    state.updateQuestionCompletion(0, 1, '', '  ');
    assert.equal(state.roomAnsweredCount(0), 1);
});

test('a failed comment save can be retried without advancing the saved value', async () => {
    const state = form();
    const element = { dataset: { original: 'Old comment' } };
    state.$wire = { saveMediaComment: async () => false, get: key => key === 'draftState' ? 'error' : 'Failed to save' };
    await state.savePhotoComment(0, 0, 0, 'New comment', element);
    assert.equal(element.dataset.original, 'Old comment');
    assert.equal(state.clientDraftState, 'error');
    state.$wire = { saveMediaComment: async () => true, get: () => 'saved' };
    await state.savePhotoComment(0, 0, 0, 'New comment', element);
    assert.equal(element.dataset.original, 'New comment');
    assert.equal(state.clientDraftState, 'saved');
});

test('answers can autosave while a large file is still transferring', async () => {
    const state = form();
    let finishUpload;
    let saves = 0;
    state.$wire = {
        async beginMediaUpload() {},
        upload(property, file, success) { finishUpload = success; },
        async saveDraft() { saves++; return true; },
        async attachMedia() { return true; },
        get: () => 'saved',
    };
    const upload = state.send({ file: {}, room: 0, question: 0 });
    await new Promise(resolve => setImmediate(resolve));
    state.scheduleAutosave();
    assert.equal(await state.flushAutosave(), true);
    assert.equal(saves, 1);
    finishUpload();
    await upload;
});

test('the gallery swipes photos while keeping vertical scrolling and video controls free', async () => {
    const { controlMediaGallery } = await import('../../resources/js/control-media-gallery.js');
    const gallery = controlMediaGallery();
    gallery.items = [{ kind: 'image' }, { kind: 'image' }, { kind: 'video' }];
    gallery.index = 0;
    const touch = (x, y) => ({ pointerType: 'touch', isPrimary: true, pointerId: 7, clientX: x, clientY: y });

    gallery.beginSwipe(touch(200, 100));
    gallery.endSwipe(touch(130, 180));
    assert.equal(gallery.index, 0);

    gallery.beginSwipe(touch(200, 100));
    gallery.endSwipe(touch(130, 105));
    assert.equal(gallery.index, 1);
    gallery.step(1);

    gallery.beginSwipe(touch(100, 100));
    gallery.endSwipe(touch(190, 100));
    gallery.handleKey({ target: { tagName: 'VIDEO' }, key: 'ArrowLeft' });
    assert.equal(gallery.index, 2);

    gallery.step(-1);
    assert.equal(gallery.index, 1);
});

test('opening from a finding shows only its evidence and restores focus to that finding', async () => {
    const { controlMediaGallery } = await import('../../resources/js/control-media-gallery.js');
    const gallery = controlMediaGallery();
    const makeGroup = (key, files) => ({
        dataset: { mediaGroup: key, mediaTitle: 'Проверить поверхность' },
        querySelectorAll: () => files.map(url => ({ dataset: { url } })),
    });
    const first = makeGroup('0-0', ['/other']);
    const second = makeGroup('1-0', ['/photo', '/video']);
    const focus = [];
    const trigger = { dataset: { findingMedia: '1-0' }, focus: () => focus.push('finding') };
    gallery.$el = { querySelectorAll: () => [first, second] };
    gallery.$refs = { viewer: { showModal() {}, querySelector: () => ({ focus: () => focus.push('viewer') }) } };
    gallery.$nextTick = callback => callback();

    gallery.openFinding(trigger);
    assert.equal(gallery.current.url, '/photo');
    assert.equal(gallery.title, 'Проверить поверхность');
    gallery.step(1);
    assert.equal(gallery.current.url, '/video');
    gallery.step(1);
    assert.equal(gallery.current.url, '/video');
    gallery.reset();
    assert.deepEqual(focus, ['viewer', 'finding']);
});

test('next focuses the first blank required answer, then advances and opens review', () => {
    const state = form();
    state.activeRoomIndex = 0;
    state.requiredQuestionsByRoom = [[0, 2], []];
    state.questionCompletion = [[true, false, false], []];
    const visits = [];
    state.focusQuestion = ({ room, question }) => visits.push([room, question]);
    state.goToRoom = index => { state.activeRoomIndex = index; visits.push(index); };
    state.openReview = () => visits.push('review');
    state.nextRoom();
    state.updateQuestionCompletion(0, 2, '', 'Готово');
    state.nextRoom();
    state.nextRoom();
    state.updateQuestionCompletion(0, 0, '', '');
    state.nextRoom();
    assert.deepEqual(visits, [[0, 2], 1, 'review', [0, 0]]);
});

test('retry saves the same edits after the server failed to acknowledge them', async () => {
    const state = form();
    let attempts = 0;
    state.$wire = {
        saveDraft: async () => ++attempts > 1,
        get: () => 'Ошибка сохранения',
    };
    state.scheduleAutosave();
    assert.equal(await state.flushAutosave(), false);
    assert.equal(state.clientDraftState, 'error');
    assert.equal(state.persistedSaveRevision, 0);
    await state.retryDraft();
    assert.equal(state.clientDraftState, 'saved');
    assert.equal(state.persistedSaveRevision, state.saveRevision);
    assert.equal(attempts, 2);
});
