import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { chromium } from 'playwright';

const base = 'http://127.0.0.1:4178';
const fixture = base + '/focus-v2-browser-smoke.html';
const image = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR5kAAAAASUVORK5CYII=', 'base64');
const dataUrl = 'data:image/png;base64,' + image.toString('base64');
const videoDataUrl = 'data:video/mp4;base64,' + Buffer.from('smoke-video-placeholder').toString('base64');
const runId = '00000000-0000-4000-8000-000000000001';
const schemaVersion = 'a'.repeat(64);
const rooms = [{
    title: 'Кухня',
    is_optional: false,
    items: [{
        question: 'Раковина чистая?',
        answer_type: 'options',
        answer_options_scored: [
            { value: 'yes', label: 'Да', points: 1 },
            { value: 'no', label: 'Нет', points: 0 },
        ],
    }],
}];
const initialSetup = () => ({
    cleaner_id: null, apartment_id: null, cleaning_date: '2026-11-24',
    inspection_date: '2026-11-24', is_assigned: false, previous_cleaner: '', comment: '',
});
const initialAnswers = () => [[{
    selected: '', custom: '', corrective: { repeats: null, action: '', recheck: null },
}]];

class FakeFocusBackend {
    constructor() {
        this.draftId = null;
        this.revision = 0;
        this.setup = initialSetup();
        this.answers = initialAnswers();
        this.media = [[[]]];
        this.sessions = new Map();
        this.startRequests = 0;
        this.chunkRequests = 0;
        this.failOneChunk = false;
        this.realWrites = 0;
        this.testWrites = 0;
        this.test = {
            draftId: null, revision: 0, runId: '00000000-0000-4000-8000-000000000002',
            setup: { ...initialSetup(), cleaner_id: '__test__' },
            answers: initialAnswers(), media: [[[]]], sessions: new Map(), state: 'sandbox',
        };
    }

    bootstrap(sandbox = false) {
        const state = sandbox ? this.test : this;
        const currentRunId = sandbox ? state.runId : runId;
        return {
            viewer: { id: 100, role: 'admin' },
            test_mode: sandbox,
            control: { id: 1, name: 'Smoke Focus', schema_version: schemaVersion },
            rooms,
            run: {
                id: currentRunId, draft_id: state.draftId, revision: state.revision,
                schema_conflict: false,
            },
            setup: state.setup,
            answers: state.answers,
            media: state.media,
            pending_uploads: [...state.sessions.values()].filter(session => ['pending', 'ready'].includes(session.status))
                .map(session => ({
                    upload_id: session.id, status: session.status, status_url: '/focus-api/media/' + session.id,
                    control_id: 1, draft_id: state.draftId, run_id: currentRunId,
                    schema_version: schemaVersion, room_index: session.room,
                    question_index: session.question, original_name: session.name,
                    size: session.size, mime: session.mime,
                })),
            people: [{ id: 8, name: 'Настоящий сотрудник', thumbnail: null }],
            apartments: [{ id: 10, name: 'Квартира 10', thumbnail: null }],
        };
    }

    describe(session) {
        const basePath = '/focus-api/media/' + session.id;
        return {
            upload_id: session.id, status: session.status,
            chunk_bytes: 524288, total_chunks: Math.ceil(session.size / 524288),
            received: [...session.received],
            urls: {
                status: basePath, chunk_base: basePath + '/chunks',
                complete: basePath + '/complete', attach: basePath + '/attach',
                cancel: basePath,
            },
        };
    }

    async wire(page) {
        await page.route('**/focus-api/**', async route => {
            const req = route.request();
            const path = new URL(req.url()).pathname;
            const segments = path.split('/').filter(Boolean);
            const json = () => req.postDataJSON();
            const reply = (data, status = 200) => route.fulfill({
                status, contentType: 'application/json', body: JSON.stringify(data),
            });
            try {
                if (path === '/focus-api/bootstrap') {
                    const sandbox = new URL(req.url()).searchParams.get('test_mode') === '1';
                    return reply(this.bootstrap(sandbox));
                }
                if (path === '/focus-api/draft') {
                    const snapshot = json();
                    const sandbox = snapshot.test_mode === true;
                    const state = sandbox ? this.test : this;
                    if (state.draftId !== null && snapshot.expected_revision !== state.revision) {
                        return reply({ message: 'Conflict', error: 'revision_conflict' }, 409);
                    }
                    state.draftId ??= sandbox ? 91 : 51;
                    state.revision += 1;
                    if (sandbox) this.testWrites += 1;
                    else this.realWrites += 1;
                    state.setup = snapshot.setup;
                    state.answers = snapshot.answers;
                    return reply({ run: {
                        id: sandbox ? state.runId : runId,
                        draft_id: state.draftId, revision: state.revision,
                    } });
                }
                if (path === '/focus-api/submit') {
                    const sandbox = json().test_mode === true;
                    const state = sandbox ? this.test : this;
                    if ([...state.sessions.values()].some(s => s.status !== 'attached' && s.status !== 'cancelled')) {
                        return reply({ message: 'Incomplete upload' }, 422);
                    }
                    if (sandbox) { this.testWrites += 1; state.state = 'sandbox_complete'; }
                    else this.realWrites += 1;
                    return reply({
                        submitted: true, test_mode: sandbox,
                        ...(!sandbox ? { result_url: '/result' } : {}),
                        score_percent: 0, total_points: 0, max_points: 1, result_zone: 'yellow',
                        media: { state: 'complete', uploads: [], attached: state.media[0][0].length },
                    });
                }
                if (path === '/focus-api/media' && req.method() === 'POST') {
                    const payload = json();
                    const sandbox = payload.test_mode === true;
                    const state = sandbox ? this.test : this;
                    this.startRequests += 1;
                    let session = state.sessions.get(payload.upload_id);
                    if (!session) {
                        session = {
                            id: payload.upload_id, room: payload.room_index,
                            question: payload.question_index,
                            name: payload.original_name, size: payload.size,
                            mime: payload.mime, received: new Set(), status: 'pending',
                            sandbox,
                        };
                        state.sessions.set(session.id, session);
                    }
                    if (sandbox) this.testWrites += 1;
                    else this.realWrites += 1;
                    return reply(this.describe(session));
                }
                if (segments[0] === 'focus-api' && segments[1] === 'media' && segments[2]) {
                    const session = this.sessions.get(segments[2]) ?? this.test.sessions.get(segments[2]);
                    if (!session) return reply({ message: 'Missing upload' }, 404);
                    const state = session.sandbox ? this.test : this;
                    if (segments[3] === 'chunks' && req.method() === 'PUT') {
                        this.chunkRequests += 1;
                        if (this.failOneChunk) {
                            this.failOneChunk = false;
                            return reply({ message: 'Temporary network error' }, 503);
                        }
                        session.received.add(Number(segments[4]));
                        return reply({ index: Number(segments[4]) });
                    }
                    if (segments[3] === 'complete') {
                        session.status = 'ready';
                        return reply(this.describe(session));
                    }
                    if (segments[3] === 'attach') {
                        if (session.status !== 'ready' && session.status !== 'attached') {
                            return reply({ message: 'Incomplete upload' }, 409);
                        }
                        if (session.status !== 'attached') {
                            state.media[session.room][session.question].push({
                                upload_id: session.id, index: state.media[session.room][session.question].length,
                                name: session.name, mime: session.mime, size: session.size,
                                url: session.mime.startsWith('video/') ? videoDataUrl : dataUrl,
                                thumbnail_url: dataUrl,
                            });
                            session.status = 'attached';
                            state.revision++;
                        }
                        return reply({
                            run: { draft_id: state.draftId, revision: state.revision },
                            room_index: session.room, question_index: session.question,
                            media_item: state.media[session.room][session.question].find(f => f.upload_id === session.id),
                        });
                    }
                    if (segments[3] === 'attached' && req.method() === 'DELETE') {
                        if (json().expected_revision !== state.revision) return reply({ message: 'Conflict' }, 409);
                        state.media[session.room][session.question] = state.media[session.room][session.question]
                            .filter(f => f.upload_id !== session.id);
                        state.sessions.delete(session.id);
                        state.revision++;
                        return reply({ deleted: true, run: { draft_id: state.draftId, revision: state.revision } });
                    }
                    if (req.method() === 'GET') return reply(this.describe(session));
                    if (req.method() === 'DELETE') {
                        session.status = 'cancelled';
                        return reply({ cancelled: true });
                    }
                }
                return reply({ message: 'Unexpected endpoint: ' + path }, 404);
            } catch (error) {
                return reply({ message: error.message || 'Test server crashed' }, 500);
            }
        });
    }
}

async function waitForVite(proc) {
    for (let i = 0; i < 100; i++) {
        if (proc.exitCode !== null) throw new Error('Vite exited before becoming ready.');
        try {
            const response = await fetch(fixture);
            if (response.ok) return;
        } catch (_) { /* Dev server still starting. */ }
        await delay(200);
    }
    throw new Error('Vite failed to start within 20 seconds.');
}
async function pageFor(context, backend) {
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await backend.wire(page);
    await page.goto(fixture, { waitUntil: 'domcontentloaded' });
    await page.getByRole('heading', { name: 'Данные проверки' }).waitFor({ timeout: 15000 });
    return { page, errors };
}
async function selectTestStaff(page) {
    await page.locator('#focus-cleaner').fill('Тестовый');
    await page.getByRole('option', { name: /Тестовый сотрудник/ }).click();
    await page.getByText('Тестовый сотрудник · серверная загрузка').waitFor({ timeout: 15000 });
    await page.getByRole('button', { name: 'К вопросам' }).click();
}
async function addPhoto(page) {
    await page.locator('#focus-camera-0-0').setInputFiles({
        name: 'photo.png', mimeType: 'image/png', buffer: image,
    });
    await page.getByRole('dialog', { name: 'Подтвердить фотографию' }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'Переснять' }).isVisible(), true);
    await page.getByRole('button', { name: 'Оставить фото' }).click();
    await page.getByRole('dialog', { name: 'Подтвердить фотографию' }).waitFor({ state: 'hidden' });
}
async function expectPhoto(page) {
    const tile = page.getByRole('button', { name: 'Открыть фото photo.png' });
    await tile.waitFor({ timeout: 15000 });
    await tile.click();
    await page.getByRole('dialog', { name: 'Просмотр фотографии' }).waitFor();
    await page.getByRole('button', { name: 'Закрыть просмотр' }).click();
}

async function main() {
    const vite = spawn(process.execPath, ['node_modules/vite/bin/vite.js',
        '--host', '127.0.0.1', '--port', '4178', '--strictPort'], {
        stdio: ['ignore', 'pipe', 'pipe'],
        // Laravel Vite deliberately rejects HMR in CI unless this isolated
        // browser fixture explicitly opts in. Never set for deployment.
        env: { ...process.env, LARAVEL_BYPASS_ENV_CHECK: '1' },
    });
    let logs = '';
    vite.stderr.on('data', chunk => { logs += chunk.toString(); });
    vite.stdout.on('data', chunk => { logs += chunk.toString(); });
    let browser;
    try {
        await waitForVite(vite);
        browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });

        // A real form must expose its comment and persist previous-cleaner
        // selection as a legacy name rather than an internal staff ID.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            const comment = page.locator('#focus-inspection-comment');
            assert.equal(await comment.isVisible(), true);
            await comment.fill('Замечание: ключи получены у администратора');
            await page.locator('#focus-previous-cleaner').fill('Настоящий');
            await page.getByRole('option', { name: 'Настоящий сотрудник' }).click();
            await page.getByText('Сохранено', { exact: true }).first().waitFor();
            assert.equal(backend.setup.comment, 'Замечание: ключи получены у администратора');
            assert.equal(backend.setup.previous_cleaner, 'Настоящий сотрудник');
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.getByRole('heading', { name: 'Данные проверки' }).waitFor();
            assert.equal(await comment.inputValue(), backend.setup.comment);
            assert.equal(await page.locator('#focus-previous-cleaner').inputValue(), 'Настоящий сотрудник');
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: first-screen comment and previous-cleaner search persist after reload');
            await context.close();
        }

        // Real server-isolated sandbox should run the full upload pipeline.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await selectTestStaff(page);
            await page.getByRole('button', { name: 'Нет' }).click();
            await addPhoto(page);
            await expectPhoto(page);
            await page.getByRole('button', { name: 'Удалить' }).waitFor({ timeout: 15000 });
            assert.equal(backend.test.media[0][0].length, 1);
            assert.ok(backend.testWrites > 1, 'Sandbox did not make real upload requests.');
            assert.equal(backend.realWrites, 0, 'Sandbox unexpectedly wrote production data.');
            await page.getByRole('button', { name: 'Удалить' }).click();
            assert.equal(await page.getByRole('button', { name: 'Открыть фото photo.png' }).count(), 0);
            await page.getByRole('button', { name: 'К сводке' }).click();
            await page.getByRole('button', { name: 'Завершить тест без статистики' }).click();
            await page.getByRole('heading', { name: 'Тест завершён' }).waitFor();
            assert.equal(backend.test.state, 'sandbox_complete');
            assert.equal(backend.realWrites, 0);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: real sandbox photo upload, gallery, delete and isolated submission');
            await context.close();
        }

        // Real browser performs the full simulated chunk-start → chunk → attach cycle.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await page.getByRole('button', { name: 'Нет' }).click();
            await addPhoto(page);
            await page.getByText('Сохранено', { exact: true }).first().waitFor({ timeout: 20000 });
            await expectPhoto(page);
            assert.equal(backend.media[0][0].length, 1);
            assert.equal(backend.startRequests, 1);
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await expectPhoto(page);
            assert.equal(backend.startRequests, 1, 'Attached photo was uploaded twice after reload.');
            page.once('dialog', dialog => dialog.accept());
            await page.getByRole('button', { name: 'Удалить' }).click();
            await page.getByRole('button', { name: 'Открыть фото photo.png' }).waitFor({ state: 'hidden' });
            assert.equal(backend.media[0][0].length, 0);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: full simulated upload, persisted refresh, no duplicate, owner delete');
            await context.close();
        }

        // A saved video must point the actual player at the video URL,
        // never at an image thumbnail (which would render a broken player).
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await page.getByRole('button', { name: 'Добавить' }).click();
            await page.locator('#focus-video-0-0').setInputFiles({
                name: 'inspection.mp4', mimeType: 'video/mp4', buffer: Buffer.from('video-fixture'),
            });
            await page.getByRole('dialog', { name: 'Подтвердить видео' }).waitFor();
            await page.getByRole('button', { name: 'Оставить видео' }).click();
            await page.getByRole('dialog', { name: 'Подтвердить видео' }).waitFor({ state: 'hidden' });
            await page.locator('video[src^="data:video/mp4"]').waitFor({ timeout: 15000 });
            assert.equal(backend.media[0][0][0].mime, 'video/mp4');
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await page.locator('video[src^="data:video/mp4"]').waitFor({ timeout: 15000 });
            assert.equal(backend.startRequests, 1);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: saved video uses original media URL before/after reload');
            await context.close();
        }

        // Interrupted upload: new WebView must recover the cached Blob and reuse its upload ID.
        {
            const backend = new FakeFocusBackend();
            backend.failOneChunk = true;
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await addPhoto(page);
            await page.getByText('Temporary network error').waitFor({ timeout: 10000 });
            const uploadId = [...backend.sessions.keys()][0];
            assert.ok(uploadId, 'No server-side reservation before a chunk failure.');
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await expectPhoto(page);
            assert.equal(backend.sessions.size, 1, 'Recovered photo incorrectly created another upload.');
            assert.equal([...backend.sessions.keys()][0], uploadId);
            assert.equal(backend.media[0][0].length, 1);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: failed network upload resumes cached photo with identical server ID');
            await context.close();
        }
    } catch (error) {
        console.error(logs.slice(-3000));
        throw error;
    } finally {
        await browser?.close();
        vite.kill('SIGTERM');
    }
}
await main();
