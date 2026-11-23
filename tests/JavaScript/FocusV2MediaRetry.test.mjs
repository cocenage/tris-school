import test from 'node:test';
import assert from 'node:assert/strict';
import { canRetryFocusFile } from '../../resources/js/focus-v2/media-upload.js';

const file = { name: 'evidence.mp4', size: 1024, type: 'video/mp4' };

test('allows an editable draft to retry a failed transfer', () => {
    assert.equal(canRetryFocusFile({ id: 'new-upload', file, status: 'error' }, true, null), true);
});

test('allows a failed registered transfer to retry after sign-off', () => {
    const registered = { id: 'upload-1', file, status: 'error', info: { upload_id: 'upload-1' } };
    assert.equal(canRetryFocusFile(registered, false, 123), true);
    assert.equal(canRetryFocusFile(registered, false, null), false);
});

test('allows reselected deferred evidence to retry after reopening review', () => {
    const deferred = { id: 'upload-2', file, status: 'error', record: { upload_id: 'upload-2' } };
    assert.equal(canRetryFocusFile(deferred, false, 123), true);
});

test('rejects unknown, mismatched and non-failed uploads after sign-off', () => {
    assert.equal(canRetryFocusFile({ id: 'unknown', file, status: 'error' }, false, 123), false);
    assert.equal(canRetryFocusFile({ id: 'mismatch', file, status: 'error', record: { upload_id: 'other' } }, false, 123), false);
    assert.equal(canRetryFocusFile({ id: 'registered', file, status: 'cancelled', info: { upload_id: 'registered' } }, false, 123), false);
    assert.equal(canRetryFocusFile({ id: 'registered', status: 'error', info: { upload_id: 'registered' } }, false, 123), false);
});
