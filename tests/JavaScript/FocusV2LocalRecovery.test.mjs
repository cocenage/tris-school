import test from 'node:test';
import assert from 'node:assert/strict';
import {
    FOCUS_RECOVERY_MAX_AGE_MS, VIDEO_RECOVERY_MAX_BYTES, canCacheFocusMedia, chooseLocalRecovery,
    createLocalRecoveryQueue, mergeDraftEdits, recoveryContentStamp, recoveryScope,
} from '../../resources/js/focus-v2/local-recovery.js';

const version = 'a'.repeat(64);
const scope = recoveryScope(14, 29, version);
const savedAt = Date.UTC(2026, 10, 23, 12, 0);
const setup = { cleaner_id: 4, apartment_id: 9, comment: '' };
const serverAnswers = [[{ selected: 'yes', custom: '', corrective: {} }]];
const changedAnswers = [[{ selected: 'no', custom: '', corrective: {} }]];
const server = {
    scope, run: { id: 'run-1', draft_id: 51, revision: 8 },
    setup, answers: serverAnswers,
};
const local = {
    scope, saved_at: savedAt, run_id: 'run-1',
    draft_id: 51, base_revision: 8, setup, answers: changedAnswers,
};

test('caches photos and short videos but never promises to recover a 100 MiB movie', () => {
    assert.equal(canCacheFocusMedia({ type: 'image/heic', size: 8 * 1024 * 1024 }), true);
    assert.equal(canCacheFocusMedia({ type: 'image/jpeg', size: 11 * 1024 * 1024 }), false);
    assert.equal(canCacheFocusMedia({ type: 'video/mp4', size: VIDEO_RECOVERY_MAX_BYTES }), true);
    assert.equal(canCacheFocusMedia({ type: 'video/quicktime', size: VIDEO_RECOVERY_MAX_BYTES + 1 }), false);
    assert.equal(canCacheFocusMedia({ type: 'video/mp4', size: 100 * 1024 * 1024 }), false);
    assert.equal(canCacheFocusMedia({ type: 'text/html', size: 123 }), false);
    assert.equal(canCacheFocusMedia(null), false);
});

test('scopes local recovery to a supervisor, control and canonical schema', () => {
    assert.equal(scope, ['focus-v2', '14', '29', version].join(':'));
    assert.notEqual(recoveryScope(15, 29, version), scope);
    assert.notEqual(recoveryScope(14, 30, version), scope);
    assert.notEqual(recoveryScope(14, 29, 'b'.repeat(64)), scope);
    assert.equal(recoveryScope(null, 29, version), null);
    assert.equal(recoveryScope(14, 29, 'bad-version'), null);
});

test('allows explicit restore only for an unchanged server revision', () => {
    assert.equal(chooseLocalRecovery(local, server, savedAt + 100), 'safe');
    assert.equal(chooseLocalRecovery({ ...local, base_revision: 7 }, server, savedAt + 100), 'conflict');
    assert.equal(chooseLocalRecovery({ ...local, run_id: 'different' }, server, savedAt + 100), 'conflict');
    assert.equal(chooseLocalRecovery({ ...local, draft_id: 52 }, server, savedAt + 100), 'conflict');
});

test('restores an unsaved first run when there is no server draft', () => {
    const first = { ...local, run_id: 'local-run', draft_id: null, base_revision: 0 };
    const emptyServer = { ...server, run: { id: 'new-run-id', draft_id: null, revision: 0 } };
    assert.equal(chooseLocalRecovery(first, emptyServer, savedAt + 100), 'safe');
    assert.equal(chooseLocalRecovery(first, server, savedAt + 100), 'conflict');
});

test('never offers a stale, differently scoped, or already-synced snapshot', () => {
    assert.equal(chooseLocalRecovery({ ...local, answers: serverAnswers }, server, savedAt + 100), 'synced');
    assert.equal(chooseLocalRecovery({ ...local, scope: recoveryScope(22, 29, version) }, server, savedAt + 100), 'none');
    assert.equal(chooseLocalRecovery(local, server, savedAt + FOCUS_RECOVERY_MAX_AGE_MS + 1), 'expired');
    assert.equal(chooseLocalRecovery({ ...local, saved_at: savedAt + 120000 }, server, savedAt), 'none');
    assert.equal(chooseLocalRecovery({ ...local, answers: null }, server, savedAt), 'none');
});

test('coalesces typing but commits a final delete after any older write', async () => {
    const operations = [];
    let releaseFirst;
    const gate = new Promise(resolve => { releaseFirst = resolve; });
    const enqueue = createLocalRecoveryQueue(async operation => {
        operations.push('start:' + operation.type + ':' + (operation.value || ''));
        if (operation.value === 'first') await gate;
        operations.push('done:' + operation.type + ':' + (operation.value || ''));
    });
    const first = enqueue({ type: 'write', value: 'first' });
    enqueue({ type: 'write', value: 'stale' });
    enqueue({ type: 'delete' });
    releaseFirst();
    await first;
    assert.deepEqual(operations, [
        'start:write:first', 'done:write:first',
        'start:delete:', 'done:delete:',
    ]);
});

test('keeps same-draft recovery available when a photo advances a server-trimmed answer', () => {
    const answer = custom => [[{ selected: 'other', custom, corrective: {
        repeats: true, recheck: false, action: 'clean again',
    } }]];
    const received = answer('stain on glass');
    const sent = answer(' stain on glass \t');
    assert.equal(recoveryContentStamp(setup, sent), recoveryContentStamp(setup, received));
    assert.notEqual(recoveryContentStamp(setup, sent), recoveryContentStamp(setup, answer('stain on floor')));
    const oldBase = JSON.stringify({ setup, answers: sent });
    const newer = { ...server, answers: received, run: { ...server.run, revision: 9 } };
    const offline = { ...local, base_content: oldBase };
    assert.equal(chooseLocalRecovery(offline, newer, savedAt + 100), 'safe');
    assert.equal(chooseLocalRecovery(offline, { ...newer, answers: answer('stain on floor') }, savedAt + 100), 'conflict');
    assert.equal(chooseLocalRecovery(offline, { ...newer, run: { ...newer.run, id: 'different' } }, savedAt + 100), 'conflict');
});
test('merges different questions in the same draft but never picks a side on the same question or setup', () => {
    const a = selected => ({ selected, custom: '', corrective: { action: '', repeats: null, recheck: null } });
    const original = [[a('yes'), a('yes')]];
    const localEdit = [[a('no'), a('yes')]];
    const serverEdit = [[a('yes'), a('other')]];
    const base = recoveryContentStamp(setup, original);
    const local = { setup, answers: localEdit };
    const server = { setup, answers: serverEdit };
    assert.deepEqual(mergeDraftEdits(base, local, server), { setup, answers: [[a('no'), a('other')]] });
    const overlap = { setup, answers: [[a('other'), a('other')]] };
    assert.equal(mergeDraftEdits(base, local, overlap), null);
    assert.deepEqual(mergeDraftEdits(base, local, overlap, 'local').answers, [[a('no'), a('other')]]);
    assert.deepEqual(mergeDraftEdits(base, local, overlap, 'server').answers, [[a('other'), a('other')]]);
    assert.equal(mergeDraftEdits(base, { ...local, setup: { ...setup, comment: 'local' } },
        { ...server, setup: { ...setup, apartment_id: 10 } }), null);
    assert.equal(mergeDraftEdits(base, local, { setup, answers: [[a('yes')]] }), null);
    assert.equal(mergeDraftEdits('invalid', local, server), null);
});