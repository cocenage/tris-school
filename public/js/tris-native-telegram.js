(() => {
    const section = document.getElementById('tris-native-telegram');
    if (!section || section.dataset.bound === 'true') return;
    const cap = window.Capacitor;
    if (cap?.getPlatform?.() !== 'android' || !cap.isPluginAvailable?.('TrisTelegramLogin')) return;
    section.dataset.bound = 'true';
    section.hidden = false;
    const browser = document.getElementById('browser-login');
    if (browser) browser.style.display = 'none';
    const button = section.querySelector('button');
    const status = section.querySelector('[role="status"]');
    async function post(path, payload) {
        const response = await fetch(path, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
            body: JSON.stringify(payload),
        });
        if (!response.ok) throw new Error('SERVER_' + response.status);
        return response.json();
    }
    button.addEventListener('click', async () => {
        button.disabled = true;
        status.textContent = 'Открываем Telegram…';
        try {
            const start = await post('/telegram/native/start', {});
            const payload = { clientId: start.client_id };
            const plugin = cap.Plugins?.TrisTelegramLogin;
            const result = plugin?.login ? await plugin.login(payload) : await cap.nativePromise('TrisTelegramLogin', 'login', payload);
            status.textContent = 'Проверяем вход…';
            const complete = await post('/telegram/native/complete', { attempt: start.attempt, id_token: result.idToken });
            if (!complete.ok || typeof complete.redirect !== 'string' || !complete.redirect.startsWith('/') || complete.redirect.startsWith('//')) throw new Error('BAD_REDIRECT');
            location.replace(complete.redirect);
        } catch (error) {
            const code = String(error?.message || '');
            const safe = /^(SERVER_[0-9]{3}|LOGIN_TIMEOUT|LOGIN_ALREADY_RUNNING|TELEGRAM_LOGIN_FAILED|TELEGRAM_START_FAILED|TELEGRAM_CALLBACK_FAILED|LOGIN_PAGE_CHANGED|INVALID_DEV_ORIGIN_OR_CLIENT|ACTIVITY_DESTROYED|EMPTY_ID_TOKEN)$/.test(code) ? ' (' + code + ')' : '';
            status.textContent = 'Не удалось войти. Повторите попытку.' + safe;
            button.disabled = false;
        }
    });
})();
