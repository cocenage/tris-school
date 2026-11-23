import test from 'node:test';
import assert from 'node:assert/strict';
import {
    FOCUS_RECOVERY_MAX_AGE_MS, chooseLocalRecovery,
    createLocalRecoveryQueue, recoveryScope,
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
