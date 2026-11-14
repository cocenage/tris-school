import test from 'node:test';
import assert from 'node:assert/strict';
import { academyOrigin, destinations, remoteDestination, workPath } from '../src/routing.mjs';
const origin = 'https://academy.example.test';
test('each launcher destination resolves to the existing Laravel path', () => {
    for (const { path } of destinations) assert.equal(workPath(path, origin), path);
});
test('control and apartment links preserve identifiers', () => {
    for (const path of ['/profile/checks/42', '/apartments/8']) {
        assert.equal(workPath(`${origin}${path}`, origin), path);
    }
});
test('external, executable, auth callback, admin and normalized links are rejected', () => {
    for (const path of ['javascript:alert(1)', 'https://evil.test/home', '//evil.test/home',
        `${origin}.evil.test/home`, `${origin}@evil.test/home`, '/admin', '/telegram/login',
        '/profile/checks/0', '/home?q=secret', '/home#token', '/%68ome', '/apartments/../home',
        '/home\\evil', 'https://u:p@academy.example.test/home']) {
        assert.throws(() => workPath(path, origin), path);
    }
});
test('remote destination requires opt-in and a configured target', () => {
    assert.throws(() => remoteDestination('/home', { origin, enabled: false }));
    assert.throws(() => remoteDestination('/home', { origin: null, enabled: true }));
    assert.equal(remoteDestination('/home', { origin, enabled: true }), `${origin}/home`);
});
test('target is HTTPS origin only and defaults to no remote server', () => {
    assert.equal(academyOrigin(''), null);
    assert.equal(academyOrigin(origin), origin);
    for (const value of ['http://academy.test', `${origin}/home`, `${origin}?q=x`,
        'https://user:password@academy.test', 'javascript:alert(1)']) {
        assert.throws(() => academyOrigin(value));
    }
});

test('launch paths exist in the Laravel router', async () => {
    const { readFile } = await import('node:fs/promises');
    const routes = await readFile(new URL('../../routes/web.php', import.meta.url), 'utf8');
    for (const { path } of destinations) assert.ok(routes.includes(`'${path}'`), path);
});

test('native bridge stays on bundled content with no remote navigation grant', async () => {
    const { readFile } = await import('node:fs/promises');
    const config = JSON.parse(await readFile(new URL('../capacitor.config.json', import.meta.url), 'utf8'));
    assert.equal(config.webDir, 'dist');
    assert.equal(config.server.url, undefined);
    assert.equal(config.server.cleartext, false);
    assert.deepEqual(config.server.allowNavigation, []);
    assert.equal(config.android.allowMixedContent, false);
});
