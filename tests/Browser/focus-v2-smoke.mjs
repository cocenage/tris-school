import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { chromium, webkit } from 'playwright';

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
    cleaner_id: 8, apartment_id: 10, cleaning_date: '2026-11-24',
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
        this.failChunksRemaining = 0;
        this.realWrites = 0;
        this.testWrites = 0;
        this.test = {
            draftId: null, revision: 0, runId: '00000000-0000-4000-8000-000000000002',
            setup: { ...initialSetup(), cleaner_id: '__test__' },
            answers: initialAnswers(), media: [[[]]], sessions: new Map(), state: 'sandbox',
        };
    }

    bootstrap(sandbox = false) {
        if (sandbox && this.test.state === 'sandbox_complete') {
            this.test = {
                draftId: null, revision: 0,
                runId: '00000000-0000-4000-8000-000000000003',
                setup: { ...initialSetup(), cleaner_id: '__test__' },
                answers: initialAnswers(), media: [[[]]], sessions: new Map(), state: 'sandbox',
            };
        }
        const state = sandbox ? this.test : this;
        const currentRunId = state.runId || state.emptyRunId || runId;
        return {
            viewer: { id: 100, role: 'admin' },
            test_mode: sandbox,
            control: { id: 1, name: 'Smoke Focus', schema_version: schemaVersion },
            rooms: this.rooms ?? rooms,
            ...(this.independentMedia ? { media_revision_scope: 'answers' } : {}),
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
            chunk_bytes: 1048576, total_chunks: Math.ceil(session.size / 1048576),
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
                    if (req.method() === 'HEAD') return route.fulfill({
                        status: this.probeReachable ? 200 : 503, contentType: 'application/json',
                    });
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
                    if (state.draftId === null) state.runId = snapshot.run_id;
                    state.draftId ??= sandbox ? 91 : 51;
                    state.revision += 1;
                    if (sandbox) this.testWrites += 1;
                    else this.realWrites += 1;
                    state.setup = snapshot.setup;
                    state.answers = snapshot.answers.map(room => room.map(answer => ({
                        ...answer, custom: answer.custom.trim(),
                    })));
                    return reply({ run: {
                        id: state.runId || runId,
                        draft_id: state.draftId, revision: state.revision,
                    } });
                }
                if (path === '/focus-api/reset') {
                    const request = json();
                    this.resetRequests = (this.resetRequests ?? 0) + 1;
                    if (request.run_id !== this.runId || request.draft_id !== this.draftId || request.expected_revision !== this.revision) {
                        return reply({ message: 'Reset conflict' }, 409);
                    }
                    this.draftId = null;
                    this.revision = 0;
                    this.runId = '00000000-0000-4000-8000-000000000010';
                    this.setup = initialSetup();
                    this.answers = initialAnswers();
                    this.media = [[[]]];
                    this.sessions.clear();
                    return reply({ reset: true });
                }
                if (path === '/focus-api/submit') {
                    const request = json();
                    const sandbox = request.test_mode === true;
                    const state = sandbox ? this.test : this;
                    if (request.expected_revision !== state.revision) {
                        return reply({ message: 'Submit revision changed' }, 409);
                    }
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
                    if (this.beforeStart) await this.beforeStart();
                    if (!payload.draft_id) return reply({message: "Draft ID required"}, 422);
                    if (payload.run_id !== (state.runId || runId) || payload.draft_id !== state.draftId) {
                        return reply({ message: 'Wrong draft or run' }, 409);
                    }
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
                        if (this.failChunksRemaining > 0) {
                            this.failChunksRemaining--;
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
                        if (this.beforeAttach) await this.beforeAttach(session);
                        const independent = this.independentMedia && json().revision_scope === 'answers';
                        if (!independent && session.status !== 'attached' && json().expected_revision !== state.revision) {
                            return reply({ message: 'Conflict' }, 409);
                        }
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
                            if (!independent) state.revision++;
                        }
                        if (this.dropAttachAcks > 0) {
                            this.dropAttachAcks--;
                            return route.abort("failed");
                        }
                        if (this.dropAttachAck) {
                            this.dropAttachAck = false;
                            return route.abort('failed');
                        }
                        const answerRevision = state.revision;
                        if (this.beforeAttachReceipt) await this.beforeAttachReceipt();
                        this.attachReceipts = (this.attachReceipts ?? 0) + 1;
                        return reply({
                            run: { draft_id: state.draftId, revision: answerRevision },
                            ...(independent ? { media_revision_scope: 'answers' } : {}),
                            room_index: session.room, question_index: session.question,
                            media_item: state.media[session.room][session.question].find(f => f.upload_id === session.id),
                        });
                    }
                    if (segments[3] === 'attached' && req.method() === 'DELETE') {
                        const independent = this.independentMedia && json().revision_scope === 'answers';
                        if (!independent && json().expected_revision !== state.revision) return reply({ message: 'Conflict' }, 409);
                        state.media[session.room][session.question] = state.media[session.room][session.question]
                            .filter(f => f.upload_id !== session.id);
                        state.sessions.delete(session.id);
                        if (!independent) state.revision++;
                        if (this.dropDeleteAck) { this.dropDeleteAck=false; return route.abort('failed'); }
                        return reply({
                            deleted: true, run: { draft_id: state.draftId, revision: state.revision },
                            ...(independent ? { media_revision_scope: 'answers' } : {}),
                        });
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
    await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
}
async function addGalleryFile(page, file) {
    await page.getByRole('button', { name: 'Добавить', exact: true }).click();
    const picker = page.getByRole('dialog', { name: 'Добавить фото или видео' });
    await picker.locator('input[type=file]').setInputFiles(file);
    const preview = page.getByRole('dialog', { name: 'Предпросмотр выбранных файлов' });
    await preview.getByRole('button', { name: 'Добавить 1' }).click();
    await preview.waitFor({ state: 'hidden' });
}
async function addPhoto(page) {
    await addGalleryFile(page, { name: 'photo.png', mimeType: 'image/png', buffer: image });
}
async function expectPhoto(page) {
    const tile = page.getByRole('button', { name: 'Открыть фото photo.png' });
    await tile.waitFor({ timeout: 15000 });
    await tile.click();
    await page.getByRole('dialog', { name: 'Галерея фото и видео' }).waitFor();
    await page.getByRole('button', { name: 'Закрыть галерею' }).click();
}

async function restoreInterruptedOriginalIfNeeded(page, { name, mimeType, buffer, video = false }) {
    const button = page.getByRole('button', { name: video ? 'Просмотреть видео ' + name : 'Открыть фото ' + name });
    try {
        await button.waitFor({ timeout: 3500 });
        return 'local-backup';
    } catch (_) {
        // WebKit and Telegram may reject or evict IndexedDB blobs. The app
        // must give a reliable server-session fallback using the same ID.
        const section = page.locator('section').filter({ has: page.getByRole('heading', { name: /Незавершённые вложения/ }) });
        const reselect = section.getByLabel('Выбрать оригинал').first();
        await reselect.waitFor({ timeout: 15000 });
        await reselect.setInputFiles({ name, mimeType, buffer });
        await button.waitFor({ timeout: 15000 });
        return 'original-reselection';
    }
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
        browser = await (process.env.FOCUS_BROWSER === 'webkit' ? webkit : chromium).launch({
            headless: true, ...(process.env.FOCUS_BROWSER === 'webkit' ? {} : { args: ['--no-sandbox', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] }),
        });

        // The server may apply DELETE and lose its reply. Do not save against the old revision.
        {
            const backend=new FakeFocusBackend();
            backend.draftId=51; backend.revision=1; backend.dropDeleteAck=true;
            const context=await browser.newContext({viewport:{width:390,height:844}});
            const {page,errors}=await pageFor(context,backend);
            await page.getByRole('button',{name:'К вопросам'}).click();
            await addPhoto(page);
            await page.getByRole('button',{name:'Удалить',exact:true}).waitFor();
            page.once('dialog',dialog=>dialog.accept());
            await page.getByRole('button',{name:'Удалить',exact:true}).click();
            await page.getByRole('button',{name:'Да',exact:true}).click();
            for(let i=0;backend.answers[0][0].selected!=='yes'&&i<45;i++) await delay(100);
            assert.equal(backend.answers[0][0].selected,'yes','Lost delete acknowledgement blocked the next answer');
            assert.equal(backend.media[0][0].length,0);
            assert.equal(await page.getByRole('button',{name:'Открыть фото photo.png'}).count(),0);
            assert.deepEqual(errors,[]);
            console.log('PASS mobile UI: lost delete receipt reconciles and does not block answers');
            await context.close();
        }
        // A media-only revision change can be safely reconciled after reopen, a real answer change cannot.
        for (const changedServerAnswer of [false,true]) {
            const backend=new FakeFocusBackend();
            backend.draftId=51; backend.revision=1; backend.dropAttachAcks=3;
            const context=await browser.newContext({viewport:{width:390,height:844}});
            const {page,errors}=await pageFor(context,backend);
            await page.getByRole('button',{name:'К вопросам'}).click();
            await addPhoto(page);
            await page.getByText('Ошибка загрузки',{exact:true}).waitFor({timeout:8000});
            await page.evaluate(()=>{Object.defineProperty(navigator,'onLine',{configurable:true,get:()=>false});window.dispatchEvent(new Event('offline'));});
            await page.getByRole('button',{name:'Да',exact:true}).click();
            await page.waitForFunction(async({schemaVersion})=>{
                const m=await import('/resources/js/focus-v2/local-recovery.js');
                const saved=await m.readLocalRecovery(m.recoveryScope(100,1,schemaVersion));
                return saved?.answers[0][0].selected==='yes';
            },{schemaVersion});
            await page.close();
            if(changedServerAnswer) {
                backend.answers[0][0].selected='no';
                backend.revision++;
            }
            const {page:reopened,errors:reopenErrors}=await pageFor(context,backend);
            if(changedServerAnswer) {
                await reopened.getByText('Показать локальные ответы для копирования').waitFor();
                assert.equal(backend.answers[0][0].selected,'no');
                assert.equal(await reopened.getByRole('button',{name:'Восстановить локальные ответы'}).count(),0);
            } else {
                await reopened.getByRole('button',{name:'Восстановить локальные ответы'}).click({timeout:7000});
                for(let i=0;backend.answers[0][0].selected!=='yes'&&i<45;i++) await delay(100);
                assert.equal(backend.answers[0][0].selected,'yes');
                assert.equal(backend.media[0][0].length,1);
                await reopened.getByRole('button',{name:/^(К вопросам|Продолжить контроль)$/}).click();
                await reopened.getByRole('button',{name:'К сводке'}).click();
                await reopened.getByRole('button',{name:'Отправить контроль'}).click();
                await reopened.getByRole('heading',{name:'Контроль завершён'}).waitFor();
            }
            assert.deepEqual([...errors,...reopenErrors],[]);
            console.log('PASS mobile UI: reopen '+(changedServerAnswer?'protects actual server changes':'restores offline answers after media-only revision and finishes'));
            await context.close();
        }

        // Offline removal must be durable, and never reserve an unsent file.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name:'К вопросам' }).click();
            await page.evaluate(() => {
                Object.defineProperty(navigator, 'onLine', { configurable:true, get:()=>false });
                window.dispatchEvent(new Event('offline'));
            });
            await addPhoto(page);
            await page.getByText('На устройстве', { exact:true }).waitFor();
            await page.getByRole('button', { name:'Убрать', exact:true }).click();
            await page.getByRole('button', { name:'Открыть фото photo.png' }).waitFor({ state:'hidden', timeout:6000 });
            assert.equal(backend.startRequests, 0);
            await page.close();
            const { page:reopened, errors:reopenErrors } = await pageFor(context, backend);
            await reopened.getByRole('button', { name:/^(К вопросам|Продолжить контроль)$/ }).click();
            assert.equal(await reopened.getByRole('button', { name:'Открыть фото photo.png' }).count(), 0);
            assert.equal(backend.startRequests, 0);
            assert.deepEqual([...errors,...reopenErrors], []);
            console.log('PASS mobile UI: offline-only removal survives reopening without a server reservation');
            await context.close();
        }
        // After all attach replies are lost once, a subsequent answer must reconcile the media revision.
        {
            const backend = new FakeFocusBackend();
            backend.draftId=51; backend.revision=1; backend.dropAttachAcks=3;
            const context=await browser.newContext({ viewport:{ width:390,height:844 } });
            const {page,errors}=await pageFor(context,backend);
            await page.getByRole('button',{name:'К вопросам'}).click();
            await addPhoto(page);
            await page.getByText('Ошибка загрузки',{exact:true}).waitFor({timeout:7000});
            await page.getByRole('button',{name:'Да',exact:true}).click();
            for(let i=0;backend.answers[0][0].selected!=='yes'&&i<45;i++) await delay(100);
            assert.equal(backend.answers[0][0].selected,'yes','Autosave used a stale revision after a lost attach receipt');
            assert.equal(await page.getByText('Конфликт черновика',{exact:true}).count(),0);
            assert.equal(backend.media[0][0].length,1);
            assert.equal(backend.chunkRequests,1);
            await page.getByRole('button',{name:'К сводке'}).click();
            await page.getByRole('button',{name:'Отправить контроль'}).click();
            await page.getByRole('heading',{name:'Контроль завершён'}).waitFor();
            assert.deepEqual(errors,[]);
            console.log('PASS mobile UI: reconciles lost attachment before answer save and final submission');
            await context.close();
        }
        // A stalled reservation must time out and replay the same upload ID.
        {
            const backend = new FakeFocusBackend();
            backend.draftId=51; backend.revision=1;
            let releaseStart;
            const firstStart=new Promise(resolve=>{releaseStart=resolve;});
            backend.beforeStart=async()=>{ if(backend.startRequests===1) await firstStart; };
            const context=await browser.newContext({ viewport:{ width:390,height:844 } });
            const {page,errors}=await pageFor(context,backend);
            await page.clock.install();
            await page.getByRole('button',{name:'К вопросам'}).click();
            await addPhoto(page);
            try {
                for(let i=0;backend.startRequests<1&&i<40;i++) await delay(50);
                assert.equal(backend.startRequests,1);
                await page.clock.fastForward(120000);
                for(let i=0;backend.media[0][0].length<1&&i<45;i++) await delay(100);
                assert.equal(backend.startRequests,2);
                assert.equal(backend.media[0][0].length,1);
                assert.equal(backend.sessions.size,1);
                assert.deepEqual(errors,[]);
            } finally { releaseStart(); }
            console.log('PASS mobile UI: stalled upload start times out and retries without duplicate');
            await context.close();
        }

        // Setup validation, room chrome, and review share the real workflow.
        {
            const backend = new FakeFocusBackend();
            backend.setup.cleaner_id = null;
            backend.setup.apartment_id = null;
            const bootstrap = backend.bootstrap.bind(backend);
            backend.bootstrap = () => {
                const data = bootstrap();
                data.rooms = [{...rooms[0],items:[rooms[0].items[0],{...rooms[0].items[0],is_optional:true,question:'Необязательное наблюдение'}]}];
                data.answers = [data.answers[0].length===2 ? data.answers[0] : [data.answers[0][0], initialAnswers()[0][0]]];
                data.media = [[data.media[0][0], data.media[0][1] ?? []]];
                return data;
            };
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const {page, errors} = await pageFor(context, backend);
            const next = page.getByRole('button',{name:'К вопросам'});
            await next.click();
            assert.equal(await page.getByRole('heading',{name:'Данные проверки'}).isVisible(), true, 'Missing setup allowed starting the inspection');
            await page.waitForFunction(() => document.activeElement?.id === 'focus-cleaner');
            const cleaner = page.locator('#focus-cleaner');
            await cleaner.fill('Настоящий');
            const option = page.locator('#focus-cleaner-options').getByRole('option').first();
            assert.ok(await option.getAttribute('id'));
            assert.equal(await cleaner.getAttribute('aria-activedescendant'), await option.getAttribute('id'));
            await cleaner.press('Enter');
            await next.click();
            await page.waitForFunction(() => document.activeElement?.id === 'focus-apartment');
            await page.locator('#focus-apartment').fill('10');
            await page.locator('#focus-apartment-options').getByRole('option').first().click();
            const inspection = page.getByLabel('Дата проверки');
            await inspection.fill('2026-11-23');
            await next.click();
            assert.equal(await inspection.getAttribute('aria-invalid'), 'true');
            await inspection.fill('2026-11-24');
            await next.click();
            const main = page.locator('#focus-question-0-0');
            await main.getByRole('button',{name:'Да',exact:true}).click();
            await page.locator('#focus-question-0-1').getByRole('button',{name:'Нет',exact:true}).click();
            assert.equal(await page.getByText('Кухня',{exact:true}).count(),1);
            await main.getByRole('button',{name:'Добавить',exact:true}).click();
            await page.getByRole('dialog',{name:'Добавить фото или видео'}).locator('input[type=file]')
                .setInputFiles({ name:'evidence.png', mimeType:'image/png', buffer:image });
            await page.getByRole('button',{name:'Добавить 1'}).click();
            await main.getByRole('button',{name:'Удалить',exact:true}).waitFor();
            await page.getByRole('button',{name:'К сводке'}).click();
            await page.getByRole('heading',{name:'Проверьте данные контроля'}).waitFor();
            assert.equal(await page.getByRole('button',{name:/Открыть замечание Необязательное наблюдение/}).count(),0);
            await page.getByRole('button',{name:'Изменить данные'}).click();
            await page.getByRole('heading',{name:'Данные проверки'}).waitFor();
            await inspection.fill('2026-11-25');
            await page.getByRole('button',{name:'Вернуться к сводке'}).click();
            await page.getByRole('heading',{name:'Проверьте данные контроля'}).waitFor();
            assert.equal(await page.getByText('2026-11-24 / 2026-11-25').count(),1);
            await page.getByRole('button',{name:'Отправить контроль'}).click();
            await page.getByRole('heading',{name:'Контроль завершён'}).waitFor();
            assert.equal(backend.answers[0][0].selected,'yes');
            assert.equal(backend.answers[0][1].selected,'no');
            assert.equal(backend.media[0][0][0].name,'evidence.png');
            assert.deepEqual(errors,[]);
            console.log('PASS mobile UI: validate setup before rooms, align optional findings, edit setup from review, and preserve answers');
            await context.close();
        }

        // Attachment is idempotent even if the server's first receipt never reaches the phone.
        {
            const backend = new FakeFocusBackend();
            backend.draftId = 51;
            backend.revision = 1;
            backend.dropAttachAck = true;
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам' }).click();
            const picker = page.getByRole('dialog', { name: 'Добавить фото или видео' });
            await page.getByRole('button', { name: 'Добавить', exact: true }).click();
            await picker.locator('input[type=file]').setInputFiles(['first.png','second.png'].map(name =>
                ({ name, mimeType: 'image/png', buffer: image })));
            await page.getByRole('button', { name: 'Добавить 2' }).click();
            for (let i = 0; backend.media[0][0].length < 2 && i < 50; i++) await delay(100);
            assert.equal(backend.media[0][0].length, 2, 'A lost attachment receipt blocked the next photo');
            assert.equal(backend.sessions.size, 2);
            assert.equal(backend.chunkRequests, 2);
            await page.getByRole('button', { name: 'Удалить', exact: true }).last().waitFor();
            assert.equal(await page.getByText('Ошибка загрузки', { exact: true }).count(), 0);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: lost attachment receipt is retried without duplicates or reuploading bytes');
            await context.close();
        }

        // The first confirmed file belongs to the local run until the server creates its draft.
        for (const differentActiveDraft of [false, true]) {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await page.evaluate(() => {
                Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false });
                window.dispatchEvent(new Event('offline'));
            });
            await addPhoto(page);
            await page.getByText('На устройстве', { exact: true }).waitFor({ timeout: 5000 });
            assert.equal(backend.startRequests, 0);
            await page.close();
            const freshRun = '00000000-0000-4000-8000-000000000098';
            if (differentActiveDraft) {
                backend.runId = freshRun;
                backend.draftId = 51;
                backend.revision = 1;
            } else backend.emptyRunId = freshRun;
            const { page: reopened, errors: reopenErrors } = await pageFor(context, backend);
            if (differentActiveDraft) {
                await reopened.getByRole('button', { name: 'К вопросам' }).click();
                assert.equal(await reopened.getByRole('button', { name: 'Открыть фото photo.png' }).count(), 0);
                assert.equal(backend.startRequests, 0);
            } else {
                for (let i = 0; backend.media[0][0].length === 0 && i < 70; i++) await delay(100);
                assert.equal(backend.media[0][0].length, 1, 'Offline photo did not resume after reopening');
                assert.equal(backend.runId, runId, 'The draft was not bound to the original offline run');
                assert.equal(backend.sessions.size, 1);
            }
            assert.deepEqual([...errors, ...reopenErrors], []);
            console.log('PASS mobile UI: first offline photo ' + (differentActiveDraft ? 'does not join another active run' : 'survives closing and uploads to its run'));
            await context.close();
        }

        // The second upload may start while the first holds the draft revision.
        {
            const backend = new FakeFocusBackend();
            backend.draftId = 51;
            backend.revision = 1;
            let releaseAttach, enteredAttach;
            const held = new Promise(resolve => { releaseAttach = resolve; });
            const entered = new Promise(resolve => { enteredAttach = resolve; });
            backend.beforeAttach = async session => {
                if (session.name === 'first.png') {
                    enteredAttach();
                    await held;
                }
            };
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await addGalleryFile(page, {name:'first.png',mimeType:'image/png',buffer:image});
            try {
                await Promise.race([entered, delay(5000).then(() => { throw new Error('First attach did not start'); })]);
                await page.getByRole('button', {name:'Да',exact:true}).click();
                await addGalleryFile(page, {name:'second.png',mimeType:'image/png',buffer:image});
                for (let i = 0; backend.startRequests < 2 && i < 50; i++) await delay(100);
                assert.equal(backend.startRequests, 2, 'Second file did not start while the first was attaching');
                assert.equal(await page.getByRole('progressbar', {name:'Передача фото и видео'}).count(), 0);
            } finally { releaseAttach(); }
            for (let i = 0; (backend.media[0][0].length !== 2 || backend.answers[0][0].selected !== 'yes') && i < 70; i++) await delay(100);
            assert.deepEqual(backend.media[0][0].map(item => item.name), ['first.png','second.png']);
            assert.equal(backend.answers[0][0].selected, 'yes');
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: parallel upload, sequential attachment, and answer edit during attach');
            await context.close();
        }

        // Room order is enforced, including the optional room; completed rooms can be revisited.
        {
            const backend = new FakeFocusBackend();
            const bootstrap = backend.bootstrap.bind(backend);
            backend.bootstrap = () => ({
                ...bootstrap(),
                rooms: [...rooms, { ...rooms[0], title: 'Ванная', is_optional: true }],
                answers: [...backend.answers.slice(0, 1), backend.answers[1] ?? initialAnswers()[0]],
                media: [...backend.media.slice(0, 1), backend.media[1] ?? [[]]],
            });
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await page.getByRole('button', { name: 'Следующая комната' }).click();
            await page.waitForFunction(() => document.activeElement?.id === 'focus-question-0-0');
            assert.equal(await page.getByRole('button', { name: /К первому пропуску/ }).count(), 0);
            await page.getByRole('button', { name: /Комнаты · 1\/2/ }).click();
            const picker = page.getByRole('dialog', { name: 'Комнаты проверки' });
            assert.equal(await picker.locator('[aria-current="step"]').count(), 1);
            assert.equal(await picker.getByRole('button', { name: /Ванная/ }).isDisabled(), true);
            await picker.getByRole('button', { name: 'Закрыть список комнат' }).click();
            await page.getByRole('button', { name: 'Нет', exact: true }).click();
            await page.getByRole('button', { name: 'Следующая комната' }).click();
            await page.getByRole('heading', { name: 'Ванная', exact: true }).waitFor();
            await page.getByRole('button', { name: 'Предыдущая комната' }).click();
            assert.equal(await page.getByRole('button', { name: 'Нет', exact: true }).getAttribute('aria-pressed'), 'true');
            await page.getByRole('button', { name: 'Следующая комната' }).click();
            await page.getByRole('heading', { name: 'Ванная', exact: true }).waitFor();
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: named room jump and next/previous preserve answers');
            await context.close();
        }

        // Resume restores the last focused question for this run only.
        {
            const backend = new FakeFocusBackend();
            backend.draftId = 51;
            backend.revision = 1;
            const bootstrap = backend.bootstrap.bind(backend);
            const bathroom = { title: 'Ванная', is_optional: false, items: [
                { ...rooms[0].items[0], question: 'Зеркало чистое?' },
                { ...rooms[0].items[0], question: 'Душ чистый?' },
            ] };
            const empty = initialAnswers()[0][0];
            backend.bootstrap = () => ({
                ...bootstrap(), rooms: [...rooms, bathroom],
                answers: [backend.answers[0], backend.answers[1] ?? [{ ...empty }, { ...empty }]],
                media: [backend.media[0], [[], []]],
            });
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByRole('button', { name: 'Следующая комната' }).click();
            await page.locator('#focus-question-1-1').getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByText('Сохранено', { exact: true }).first().waitFor();
            await page.close();
            const { page: reopened, errors: reopenErrors } = await pageFor(context, backend);
            await reopened.getByRole('button', { name: 'Продолжить контроль' }).click();
            await reopened.waitForFunction(() => document.activeElement?.id === 'focus-question-1-1');
            assert.equal(await reopened.locator('#focus-question-1-1').getByRole('button', { name: 'Да', exact: true }).getAttribute('aria-pressed'), 'true');
            await reopened.getByRole('button', { name: 'К сводке' }).click();
            await reopened.waitForFunction(() => document.activeElement?.id === 'focus-question-1-0');
            assert.equal(await reopened.locator('#focus-question-1-0').evaluate(el => el.classList.contains('border-red-400')), true);

            // A fresh server run must not open the old local position.
            backend.draftId = null;
            backend.revision = 0;
            backend.answers = initialAnswers();
            const originalBootstrap = backend.bootstrap;
            backend.bootstrap = () => {
                const state = originalBootstrap();
                state.run.id = '00000000-0000-4000-8000-000000000099';
                return state;
            };
            await reopened.reload({ waitUntil: 'domcontentloaded' });
            await reopened.getByRole('button', { name: 'К вопросам' }).waitFor();
            assert.equal(await reopened.getByRole('button', { name: 'Продолжить контроль' }).count(), 0);
            assert.deepEqual([...errors, ...reopenErrors], []);
            console.log('PASS mobile UI: resume exact question, jump to first required gap, isolate new runs');
            await context.close();
        }

        // Chromium's virtual devices exercise capture, not the file chooser.
        if (process.env.FOCUS_BROWSER !== 'webkit') {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 }, permissions: ['camera', 'microphone'] });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            let filePickers = 0;
            page.on('filechooser', () => filePickers++);
            for (const video of [false, true]) {
                await page.getByRole('button', { name: 'Добавить', exact: true }).click();
                await page.getByRole('button', { name: video ? 'Снять видео' : 'Сделать фото' }).click();
                const camera = page.getByRole('dialog', { name: video ? 'Камера для видео' : 'Камера для фото' });
                await camera.getByRole('button', { name: video ? 'Начать запись' : 'Сделать снимок' }).click();
                if (video) {
                    await delay(1300);
                    await camera.getByRole('button', { name: 'Остановить запись' }).click();
                }
                const preview = page.getByRole('dialog', { name: video ? 'Подтвердить видео' : 'Подтвердить фотографию' });
                await preview.waitFor();
                assert.equal(backend.startRequests, video ? 1 : 0, 'Must not upload before preview confirmation.');
                await preview.getByRole('button', { name: video ? 'Оставить видео' : 'Оставить фото' }).click();
                for (let i = 0; backend.media[0][0].length !== (video ? 2 : 1) && i < 80; i++) await delay(100);
                assert.equal(backend.media[0][0].length, video ? 2 : 1);
            }
            assert.equal(filePickers, 0);
            assert.equal(backend.media[0][0][0].mime, 'image/jpeg');
            assert.match(backend.media[0][0][1].mime, /^video\/(mp4|webm)$/);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: camera photo and recorded video upload only after preview');
            await context.close();
        }

        // Denial must not silently substitute a file picker for the camera.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            await context.addInitScript(() => {
                Object.defineProperty(navigator.mediaDevices, 'getUserMedia', {
                    value: () => Promise.reject(new DOMException('Denied', 'NotAllowedError')),
                });
            });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await page.getByRole('button', { name: 'Добавить', exact: true }).click();
            await page.getByRole('button', { name: 'Сделать фото' }).click();
            await page.getByRole('alert').filter({ hasText: /Нет доступа к камере/ }).waitFor();
            const camera = page.getByRole('dialog', { name: 'Камера для фото' });
            await camera.locator('input[type=file]').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: image });
            await page.getByRole('dialog', { name: 'Предпросмотр выбранных файлов' })
                .getByRole('button', { name: 'Добавить 1' }).click();
            await expectPhoto(page);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: camera denial is explicit, gallery is a separate action');
            await context.close();
        }

        // Hide the comment editor without changing existing data; previous-cleaner
        // selection as a legacy name rather than an internal staff ID.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            backend.setup.comment = 'Старое замечание';
            const { page, errors } = await pageFor(context, backend);
            const comment = page.locator('#focus-inspection-comment');
            assert.equal(await comment.count(), 0);
            assert.equal(await page.getByText('TRIS Academy · Focus Control', { exact: true }).count(), 0);
            assert.equal(await page.getByRole('link', { name: /старый/i }).count(), 0);
            await page.locator('#focus-previous-cleaner').fill('Настоящий');
            await page.getByRole('option', { name: 'Настоящий сотрудник' }).click();
            await page.getByText('Сохранено', { exact: true }).first().waitFor();
            assert.equal(backend.setup.comment, 'Старое замечание');
            assert.equal(backend.setup.previous_cleaner, 'Настоящий сотрудник');
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.getByRole('heading', { name: 'Данные проверки' }).waitFor();
            assert.equal(await comment.count(), 0);
            assert.equal(await page.locator('#focus-previous-cleaner').inputValue(), 'Настоящий сотрудник');
            await page.locator('#focus-previous-cleaner').fill('Не указан');
            await page.getByRole('option', { name: 'Не указан' }).click();
            await page.waitForFunction(() => document.querySelector('#focus-previous-cleaner')?.value === 'Не указан');
            for (let attempt = 0; backend.setup.previous_cleaner !== '' && attempt < 40; attempt++) {
                await delay(100);
            }
            assert.equal(backend.setup.previous_cleaner, '');
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: clean setup, legacy comment preserved, previous-staff search and clear option persist');
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
            page.once('dialog', dialog => dialog.accept());
            await page.getByRole('button', { name: 'Удалить' }).click();
            await page.getByRole('button', { name: 'Открыть фото photo.png' }).waitFor({ state: 'hidden', timeout: 15000 });
            await page.getByRole('button', { name: 'К сводке' }).click();
            await page.getByRole('heading', { name: 'Проверьте данные контроля' }).waitFor();
            assert.equal(await page.getByText('Замечания — проверьте перед отправкой').isVisible(), true);
            assert.equal(await page.getByRole('button', { name: /Открыть замечание Раковина чистая/ }).count(), 1);
            await page.getByRole('button', { name: /Открыть замечание Раковина чистая/ }).click();
            await page.locator('#focus-question-0-0').waitFor();
            await page.getByRole('button', { name: 'К сводке' }).click();
            await page.getByRole('button', { name: 'Завершить тест без статистики' }).click();
            await page.getByRole('heading', { name: 'Тест завершён' }).waitFor();
            assert.equal(backend.test.state, 'sandbox_complete');
            assert.equal(backend.realWrites, 0);
            await page.getByRole('button', { name: 'Начать новый тест' }).click();
            await page.getByRole('heading', { name: 'Данные проверки' }).waitFor();
            await page.getByText('Тестовый сотрудник · серверная загрузка').waitFor();
            assert.equal(backend.test.state, 'sandbox');
            assert.equal(backend.test.draftId, null);
            assert.equal(backend.test.media[0][0].length, 0);
            assert.equal(backend.realWrites, 0);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: real sandbox photo upload, gallery, delete and isolated submission');
            await context.close();
        }

        // Multi-file gallery: preview and removing one photo must happen
        // before any server reservation, then fullscreen swipe/arrows work.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await page.getByRole('button', { name: 'Добавить' }).click();
            const galleryInput = page.locator('input[type=file][multiple]').last();
            await galleryInput.setInputFiles([
                { name: 'first.png', mimeType: 'image/png', buffer: image },
                { name: 'second.png', mimeType: 'image/png', buffer: image },
            ]);
            await page.getByRole('dialog', { name: 'Предпросмотр выбранных файлов' }).waitFor();
            assert.equal(backend.startRequests, 0, 'Media upload started before batch confirmation');
            await page.getByRole('button', { name: 'Посмотреть выбранный файл 2' }).click();
            await page.getByRole('button', { name: 'Убрать файл' }).click();
            assert.equal(await page.getByRole('button', { name: 'Посмотреть выбранный файл 2' }).count(), 0);
            await page.getByRole('button', { name: 'Добавить 1' }).click();
            await page.getByRole('dialog', { name: 'Предпросмотр выбранных файлов' }).waitFor({ state: 'hidden' });
            await page.getByRole('button', { name: 'Открыть фото first.png' }).waitFor();
            for (let i = 0; backend.media[0][0].length !== 1 && i < 80; i++) await delay(100);
            assert.equal(backend.media[0][0].length, 1);
            await addPhoto(page);
            for (let i = 0; backend.media[0][0].length !== 2 && i < 80; i++) await delay(100);
            assert.equal(backend.media[0][0].length, 2);
            await page.getByRole('button', { name: 'Открыть фото first.png' }).click();
            await page.getByRole('dialog', { name: 'Галерея фото и видео' }).waitFor();
            await page.getByRole('button', { name: 'Следующее вложение' }).click();
            await page.getByText('2 из 2').waitFor();
            await page.getByRole('button', { name: 'Предыдущее вложение' }).click();
            await page.getByText('1 из 2').waitFor();
            await page.getByRole('button', { name: 'Закрыть галерею' }).click();
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: confirm/remove gallery batch, video/photo carousel navigation');
            await context.close();
        }

        // Replacement preserves the old server photo until the new one has
        // been uploaded and attached. Then only the old upload ID is removed.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await addPhoto(page);
            const replacement = page.locator('label').filter({ hasText: 'Заменить' }).locator('input[type=file]').first();
            await replacement.waitFor({ timeout: 15000 });
            await replacement.setInputFiles({ name: 'replacement.png', mimeType: 'image/png', buffer: image });
            await page.getByRole('dialog', { name: 'Подтвердить фотографию' }).waitFor();
            assert.equal(backend.media[0][0].length, 1, 'Old media vanished before replacement was uploaded.');
            await page.getByRole('button', { name: 'Оставить фото' }).click();
            for (let i = 0; backend.media[0][0][0]?.name !== 'replacement.png' && i < 80; i++) await delay(100);
            assert.equal(backend.media[0][0].length, 1);
            assert.equal(backend.media[0][0][0].name, 'replacement.png');
            assert.equal(backend.sessions.size, 1);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: confirmed replacement preserves old photo until new attach');
            await context.close();
        }

        // Offline saves must not claim server acknowledgement; reconnect
        // should retry the same locally recoverable draft automatically.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await context.setOffline(true);
            await page.evaluate(() => window.dispatchEvent(new Event('offline')));
            await page.getByText('Нет интернета', { exact: true }).waitFor();
            await page.getByLabel('Назначенная уборка').check();
            assert.equal(backend.realWrites, 0, 'Offline edit unexpectedly wrote server draft');
            assert.equal(await page.getByText('Сохранено', { exact: true }).count(), 0);
            await context.setOffline(false);
            await page.evaluate(() => window.dispatchEvent(new Event('online')));
            for (let i = 0; !backend.setup.is_assigned && i < 80; i++) {
                await delay(100);
            }
            assert.equal(backend.setup.is_assigned, true);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: offline draft edit is not claimed saved and syncs on reconnect');
            await context.close();
        }

        // Real browser performs the full simulated chunk-start → chunk → attach cycle.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await page.getByRole('button', { name: 'Нет' }).click();
            await addPhoto(page);
            await page.getByText('Сохранено', { exact: true }).first().waitFor({ timeout: 20000 });
            await expectPhoto(page);
            assert.equal(backend.media[0][0].length, 1);
            assert.equal(backend.startRequests, 1);
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
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
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await addGalleryFile(page, {
                name: 'inspection.mp4', mimeType: 'video/mp4', buffer: Buffer.from('video-fixture'),
            });
            await page.getByRole('button', { name: /Просмотреть видео inspection.mp4/ }).waitFor({ timeout: 15000 });
            await page.getByRole('button', { name: /Просмотреть видео inspection.mp4/ }).click();
            const videoDialog = page.getByRole('dialog', { name: 'Галерея фото и видео' });
            await videoDialog.waitFor();
            assert.equal(await videoDialog.locator('video').getAttribute('src'), videoDataUrl,
                'The player must receive original video bytes, not the JPEG thumbnail');
            await page.getByRole('button', { name: 'Закрыть галерею' }).click();
            assert.equal(backend.media[0][0][0].mime, 'video/mp4');
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await page.getByRole('button', { name: /Просмотреть видео inspection.mp4/ }).waitFor({ timeout: 15000 });
            assert.equal(backend.startRequests, 1);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: saved video uses original media URL before/after reload');
            await context.close();
        }

        // A server-ready video needs no original file after Telegram closes.
        // It should be attached automatically during bootstrap.
        {
            const backend = new FakeFocusBackend();
            backend.draftId = 51;
            backend.revision = 1;
            const uploadId = '00000000-0000-4000-8000-000000000091';
            backend.sessions.set(uploadId, {
                id: uploadId, room: 0, question: 0, name: 'ready-video.mp4',
                size: 128, mime: 'video/mp4', status: 'ready', received: new Set(),
            });
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await page.getByRole('button', { name: /Просмотреть видео ready-video.mp4/ }).waitFor({ timeout: 15000 });
            assert.equal(backend.media[0][0].length, 1, 'Server-ready file was not automatically attached.');
            assert.equal(backend.sessions.get(uploadId).status, 'attached');
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: automatically attaches server-ready video with no file reselection');
            await context.close();
        }

        // Reopening with unsaved answers should not strand an already uploaded
        // video once the supervisor has decided which answers to keep.
        for (const restore of [true, false]) {
            const backend = new FakeFocusBackend();
            backend.draftId = 51;
            backend.revision = 1;
            const uploadId = '00000000-0000-4000-8000-000000000092';
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            // Load the test reader before disconnecting the simulated device.
            await page.evaluate(() => import('/resources/js/focus-v2/local-recovery.js').then(() => {}));
            await context.setOffline(true);
            await page.evaluate(() => window.dispatchEvent(new Event('offline')));
            await page.getByText('Нет интернета', { exact: true }).waitFor();
            await page.getByLabel('Назначенная уборка').check();
            await page.waitForFunction(async () => {
                const { readLocalRecovery, recoveryScope } = await import('/resources/js/focus-v2/local-recovery.js');
                const saved = await readLocalRecovery(recoveryScope(100, 1, 'a'.repeat(64)));
                return saved?.setup?.is_assigned === true;
            });
            // Model a final upload acknowledgement arriving on the server as
            // the WebView closes. No media Blob is available on the device.
            await page.close();
            backend.sessions.set(uploadId, {
                id: uploadId, room: 0, question: 0, name: 'recovered-ready.mp4',
                size: 128, mime: 'video/mp4', status: 'ready', received: new Set(),
            });
            await context.setOffline(false);
            const { page: reopened, errors: reopenErrors } = await pageFor(context, backend);
            const choice = reopened.getByRole('button', {
                name: restore ? 'Восстановить локальные ответы' : 'Оставить серверную версию',
            });
            await choice.waitFor();
            assert.equal(backend.sessions.get(uploadId).status, 'ready', 'Media attached before choosing answers.');
            await choice.click();
            for (let i = 0; backend.media[0][0].length === 0 && i < 60; i++) await delay(100);
            assert.equal(backend.media[0][0].length, 1, 'Ready file did not attach after resolving local recovery.');
            assert.equal(backend.media[0][0][0].upload_id, uploadId);
            // Attachment can finish just before the deferred answer write.
            if (restore) await reopened.getByText('Сохранено', { exact: true }).waitFor();
            assert.equal(backend.setup.is_assigned, restore);
            assert.equal(backend.startRequests, 0, 'Ready file should not restart the upload.');
            assert.deepEqual([...errors, ...reopenErrors], []);
            console.log('PASS mobile UI: ready video attaches after choosing ' + (restore ? 'local answers' : 'server answers'));
            await context.close();
        }

        // A partial upload with no device cache explains which question
        // needs media and can be replaced without an original.
        {
            const backend = new FakeFocusBackend();
            backend.draftId = 51;
            backend.revision = 1;
            const uploadId = '00000000-0000-4000-8000-000000000093';
            backend.sessions.set(uploadId, {
                id: uploadId, room: 0, question: 0, name: 'missing.png',
                size: image.length, mime: 'image/png', status: 'pending', received: new Set(),
            });
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            const recovery = page.locator('section').filter({ has: page.getByRole('heading', { name: /Незавершённые вложения/ }) });
            await page.getByRole('button', { name: 'К вопросам' }).click();
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByRole('button', { name: 'К сводке' }).click();
            await page.waitForFunction(id => document.activeElement?.id === 'focus-recovery-' + id, uploadId);
            assert.match(await recovery.getByRole('button', { name: /вопрос 1/ }).innerText(), /Кухня/);
            await recovery.getByLabel('Выбрать оригинал').setInputFiles({ name: 'different.png', mimeType: 'image/png', buffer: image });
            await recovery.getByRole('alert').filter({ hasText: 'Это другой файл' }).waitFor();
            page.once('dialog', dialog => dialog.accept());
            await recovery.getByRole('button', { name: 'Снять или выбрать заново' }).click();
            const picker = page.getByRole('dialog', { name: 'Добавить фото или видео' });
            await picker.waitFor();
            assert.equal(backend.sessions.get(uploadId).status, 'cancelled');
            await picker.locator('input[type=file]').setInputFiles({ name: 'photo.png', mimeType: 'image/png', buffer: image });
            await page.getByRole('button', { name: 'Добавить 1' }).click();
            for (let i = 0; !backend.media[0][0].length && i < 80; i++) await delay(100);
            assert.equal(backend.media[0][0][0].name, 'photo.png');
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: missing originals can be replaced in the correct question');
            await context.close();
        }

        // Interrupted upload: new WebView must recover the cached Blob and reuse its upload ID.
        {
            const backend = new FakeFocusBackend();
            // Exhaust automatic retry attempts; verify recovery on WebView reopen.
            backend.failChunksRemaining = 3;
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await addPhoto(page);
            await page.getByText('Temporary network error').waitFor({ timeout: 10000 });
            const uploadId = [...backend.sessions.keys()][0];
            assert.ok(uploadId, 'No server-side reservation before a chunk failure.');
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            const recoveryPath = await restoreInterruptedOriginalIfNeeded(page, {
                name: 'photo.png', mimeType: 'image/png', buffer: image,
            });
            await expectPhoto(page);
            assert.ok(['local-backup', 'original-reselection'].includes(recoveryPath));
            assert.equal(backend.sessions.size, 1, 'Recovered photo incorrectly created another upload.');
            assert.equal([...backend.sessions.keys()][0], uploadId);
            assert.equal(backend.media[0][0].length, 1);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: failed network upload resumes cached photo with identical server ID');
            await context.close();
        }
        // Saving answers must not wait for any individual photo receipt.
        {
            const backend = new FakeFocusBackend();
            backend.independentMedia = true;
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам', exact: true }).click();
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByText('Сохранено', { exact: true }).waitFor();
            const originalRun = backend.runId;
            const originalDraft = backend.draftId;
            const firstRevision = backend.revision;

            let releaseReceipt;
            const receiptGate = new Promise(resolve => { releaseReceipt = resolve; });
            let uploadAttached;
            const attached = new Promise(resolve => { uploadAttached = resolve; });
            backend.beforeAttachReceipt = async () => {
                uploadAttached();
                await receiptGate;
            };
            try {
                await addPhoto(page);
                await Promise.race([attached, delay(7000).then(() => { throw new Error('Upload never reached receipt gate'); })]);
                assert.equal(backend.revision, firstRevision, 'Attaching evidence changed the answer revision');
                await page.getByRole('button', { name: 'Нет', exact: true }).click();
                await page.getByText('Сохранено', { exact: true }).waitFor({ timeout: 5000 });
                assert.equal(backend.answers[0][0].selected, 'no');
                assert.equal(backend.revision, firstRevision + 1);
            } finally {
                releaseReceipt();
            }
            await expectPhoto(page);
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByText('Сохранено', { exact: true }).waitFor({ timeout: 5000 });
            assert.equal(backend.revision, firstRevision + 2, 'A late attachment receipt rewound the answer revision');

            backend.beforeAttachReceipt = null;
            backend.dropAttachAcks = 3;
            await addGalleryFile(page, { name: 'second.png', mimeType: 'image/png', buffer: image });
            await page.getByRole('button', { name: 'Открыть фото second.png' }).waitFor({ timeout: 25000 });
            for (let tries = 0; backend.attachReceipts !== 2 && tries < 125; tries++) await delay(100);
            assert.equal(backend.attachReceipts, 2, 'The client did not recover the lost attach receipts automatically');
            assert.equal(backend.sessions.size, 2, 'Lost receipts created a duplicate upload');
            assert.equal(backend.revision, firstRevision + 2);

            await page.getByRole('button', { name: 'К сводке' }).click();
            await page.getByRole('button', { name: 'Отправить контроль' }).click();
            await page.getByRole('link', { name: 'Результат', exact: true }).waitFor();
            assert.equal(backend.runId, originalRun);
            assert.equal(backend.draftId, originalDraft);
            assert.equal(backend.media[0][0].length, 2);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: delayed and lost photo receipts do not block or roll back answers or recreate the draft');
            await context.close();
        }
        // The Laravel controller trims free text before acknowledging the draft.
        // An attachment conflict must compare that canonical answer, not its raw input.
        {
            const backend = new FakeFocusBackend();
            backend.rooms = structuredClone(rooms);
            backend.rooms[0].items[0].answer_type = 'both';
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам', exact: true }).click();
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByRole('textbox', { name: 'Комментарий', exact: true }).fill(' stain on glass ');
            await page.getByText('Сохранено', { exact: true }).waitFor();
            assert.equal(backend.answers[0][0].custom, 'stain on glass');
            const originalRun = backend.runId;
            backend.beforeAttach = async () => {
                backend.beforeAttach = null;
                backend.revision++;
            };
            await addPhoto(page);
            await expectPhoto(page);
            await page.getByRole('button', { name: 'К сводке' }).click();
            await page.getByRole('button', { name: 'Отправить контроль' }).click();
            await page.getByRole('link', { name: 'Результат', exact: true }).waitFor();
            assert.equal(backend.runId, originalRun);
            assert.equal(backend.answers[0][0].custom, 'stain on glass');
            assert.equal(backend.media[0][0].length, 1);
            assert.deepEqual(errors, []);
            console.log('PASS: photo and normalized answer submit in the same draft');
            await context.close();
        }

        // A photo can move the server revision even when every answer there is unchanged.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByText('Сохранено', { exact: true }).waitFor();
            const originalRun = backend.runId;
            backend.media[0][0].push({
                upload_id: 'server-photo', name: 'server-photo.png', mime: 'image/png', size: image.length,
                url: dataUrl, thumbnail_url: dataUrl,
            });
            backend.revision++;
            await page.getByRole('button', { name: 'Нет', exact: true }).click();
            await page.getByText('Сохранено', { exact: true }).waitFor({ timeout: 5000 });
            assert.equal(backend.answers[0][0].selected, 'no');
            assert.equal(backend.runId, originalRun);
            assert.equal(backend.media[0][0].length, 1);
            await page.getByRole('button', { name: 'Открыть фото server-photo.png' }).waitFor();
            backend.beforeAttach = async () => {
                backend.beforeAttach = null;
                backend.revision++;
            };
            await addPhoto(page);
            await expectPhoto(page);
            assert.equal(backend.media[0][0].length, 2);
            backend.failChunksRemaining = 3;
            await addGalleryFile(page, { name: 'failed.png', mimeType: 'image/png', buffer: image });
            await page.getByText('Temporary network error').waitFor({ timeout: 15000 });
            const failed = [...backend.sessions.values()].find(session => session.name === 'failed.png');
            assert.ok(failed);
            await page.getByRole('button', { name: 'Убрать', exact: true }).click();
            await page.getByText('Temporary network error').waitFor({ state: 'hidden' });
            assert.equal(failed.status, 'cancelled');
            assert.deepEqual(errors, []);
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByText('Сохранено', { exact: true }).waitFor();
            await page.getByRole('button', { name: 'К сводке' }).click();
            backend.revision++;
            await page.getByRole('button', { name: 'Отправить контроль' }).click();
            await page.getByRole('link', { name: 'Результат', exact: true }).waitFor();
            assert.equal(backend.runId, originalRun);
            assert.equal(backend.answers[0][0].selected, 'yes');
            assert.equal(backend.media[0][0].length, 2);
            console.log('PASS mobile UI: attach/save/submit reconcile photo revisions; a failed file can be removed without losing the draft');
            await context.close();
        }
        // A stale form must not be a dead end, even with a failed save in memory.
        {
            const backend = new FakeFocusBackend();
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByText('Сохранено', { exact: true }).waitFor();
            backend.answers[0][0].custom = 'Другое изменение на сервере';
            backend.revision++;
            await page.getByRole('button', { name: 'Нет', exact: true }).click();
            await page.getByText('Конфликт черновика', { exact: true }).waitFor();
            const restart = page.getByRole('button', { name: 'Начать новую проверку', exact: true });
            page.once('dialog', dialog => dialog.dismiss());
            await restart.click({ timeout: 4000 });
            assert.equal(backend.resetRequests ?? 0, 0, 'Cancelling confirmation must not reset the draft.');
            const originalRun = backend.runId;
            backend.runId = '00000000-0000-4000-8000-000000000099';
            page.once('dialog', dialog => dialog.accept());
            await restart.click();
            await page.getByText('На сервере уже другой черновик. Ничего не сброшено.', { exact: false }).waitFor();
            assert.equal(backend.resetRequests ?? 0, 0, 'The fresh revision must never be used to reset a different run.');
            backend.runId = originalRun;
            page.once('dialog', dialog => dialog.accept());
            await restart.click();
            await page.getByRole('heading', { name: 'Данные проверки' }).waitFor();
            assert.equal(backend.resetRequests, 1);
            assert.equal(backend.draftId, null);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByText('Сохранено', { exact: true }).waitFor();
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: conflicted draft can be discarded without reloading');
            await context.close();
        }
        // A short service outage does not necessarily flip navigator.onLine.
        {
            const backend = new FakeFocusBackend();
            backend.failChunksRemaining = 3;
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            assert.equal(await page.evaluate(() => navigator.onLine), true);
            await addPhoto(page);
            await page.getByText('Temporary network error').waitFor({ timeout: 10000 });
            const id = [...backend.sessions.keys()][0];
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            await page.getByText('Сохранено', { exact: true }).waitFor();
            for (let count = 0; count < 80 && !backend.media[0][0].length; count++) await delay(100);
            assert.equal(backend.sessions.size, 1);
            assert.equal(backend.media[0][0][0]?.upload_id, id, 'Photo was never auto-retried and attached.');
            assert.equal(backend.answers[0][0].selected, 'yes');
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: a transient photo failure self-recovers without network events or repeat selection');
            await context.close();
        }
        // A WebView reporting offline is not authoritative if TRIS still answers.
        {
            const backend = new FakeFocusBackend();
            backend.probeReachable = true;
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: 'К вопросам', exact: true }).click();
            await page.evaluate(() => {
                Object.defineProperty(navigator, 'onLine', { configurable: true, get: () => false });
                window.dispatchEvent(new Event('offline'));
            });
            await addPhoto(page);
            await page.getByRole('button', { name: 'Да', exact: true }).click();
            for (let i = 0; i < 80 && !backend.media[0][0].length; i++) await delay(100);
            assert.equal(backend.media[0][0].length, 1, 'Reachable server must accept photo despite the offline flag.');
            await page.getByText('Сохранено', { exact: true }).waitFor();
            assert.equal(await page.evaluate(() => navigator.onLine), false);
            await page.getByRole('button', { name: 'К сводке' }).click();
            await page.getByRole('button', { name: 'Отправить контроль' }).click();
            await page.getByRole('link', { name: 'Результат', exact: true }).waitFor();
            assert.equal(backend.runId, runId);
            assert.equal(backend.answers[0][0].selected, 'yes');
            assert.equal(backend.sessions.size, 1);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: reachable TRIS saves, attaches and submits despite the offline flag');
            await context.close();
        }
        // Small videos use the same best-effort IndexedDB recovery as photos.
        // Do not claim the same guarantee for videos larger than the backup limit.
        {
            const backend = new FakeFocusBackend();
            backend.failChunksRemaining = 3;
            const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
            const { page, errors } = await pageFor(context, backend);
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            await addGalleryFile(page, {
                name: 'cached-clip.mp4', mimeType: 'video/mp4', buffer: Buffer.from('short-test-video'),
            });
            await page.getByText('Temporary network error').waitFor({ timeout: 15000 });
            const uploadId = [...backend.sessions.keys()][0];
            assert.ok(uploadId);
            await page.reload({ waitUntil: 'domcontentloaded' });
            await page.getByRole('button', { name: /^(К вопросам|Продолжить контроль)$/ }).click();
            const recoveryPath = await restoreInterruptedOriginalIfNeeded(page, {
                name: 'cached-clip.mp4', mimeType: 'video/mp4', buffer: Buffer.from('short-test-video'), video: true,
            });
            assert.ok(['local-backup', 'original-reselection'].includes(recoveryPath));
            // The local preview appears before the resumed server transfer
            // completes. Only server-acknowledged attachment counts as saved.
            for (let i = 0; backend.media[0][0].length !== 1 && i < 100; i++) await delay(100);
            assert.equal(backend.sessions.size, 1, 'Video recovery created a second upload.');
            assert.equal([...backend.sessions.keys()][0], uploadId);
            assert.equal(backend.media[0][0].length, 1);
            assert.deepEqual(errors, []);
            console.log('PASS mobile UI: short video recovers from interrupted upload after WebView reload');
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
