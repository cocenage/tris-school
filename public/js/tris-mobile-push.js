(() => {
    if (window.__trisPushBridge) return;
    const cap = window.Capacitor;
    if (cap?.getPlatform?.() !== 'android') {
        const status = document.getElementById('tris-push-status');
        if (status) status.textContent = 'Регистрация доступна в Android APK.';
        return;
    }
    const native = cap.Plugins?.PushNotifications;
    if (!native && !(cap.isPluginAvailable?.('PushNotifications') && cap.nativePromise && cap.addListener)) {
        const status = document.getElementById('tris-push-status');
        if (status) status.textContent = 'Push-плагин не установлен в APK.';
        return;
    }
    window.__trisPushBridge = true;
    const meta = () => document.querySelector('meta[name="tris-mobile-push"]');
    const status = (message) => {
        const target = document.getElementById('tris-push-status');
        if (target) target.textContent = message;
    };
    const call = (method) => native?.[method] ? native[method]() : cap.nativePromise('PushNotifications', method, {});
    const listen = (name, handler) => native?.addListener ? native.addListener(name, handler) : cap.addListener('PushNotifications', name, handler);
    async function post(path, body = {}) {
        if (!meta()) throw new Error('No authenticated push page');
        const response = await fetch('/profile/push/' + path, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
            body: JSON.stringify(body),
        });
        if (!response.ok || !(await response.json()).ok) throw new Error('Push request rejected');
    }
    let enabled = meta()?.content === 'enabled';
    let registering = false;
    const show = () => {
        const toast = document.createElement('button');
        toast.textContent = 'TRIS DEV · Получено тестовое уведомление';
        Object.assign(toast.style, { position: 'fixed', top: '20px', left: '16px', right: '16px', zIndex: '999999', padding: '16px', border: '1px solid #ddd', borderRadius: '12px', background: '#fff', color: '#111' });
        toast.onclick = () => location.assign('/profile/push');
        document.body.append(toast);
        setTimeout(() => toast.remove(), 6000);
    };
    async function event(notification, name) {
        const data = notification?.data;
        if (!meta() || data?.kind !== 'tris_test' || !/^[0-9a-f-]{36}$/i.test(data.notification_id ?? '')) return;
        try { await post('event', { id: data.notification_id, event: name }); } catch { /* Delivery telemetry is best effort. */ }
        if (name === 'opened') location.assign('/profile/push');
        else { status('Тестовое уведомление получено. Обновите историю.'); show(); }
    }
    async function register(ask = false) {
        if (registering || !meta()) return;
        registering = true;
        try {
            let perm = await call('checkPermissions');
            if (ask && ['prompt', 'prompt-with-rationale'].includes(perm.receive)) perm = await call('requestPermissions');
            if (perm.receive !== 'granted') {
                status('Уведомления запрещены. Разрешите их в настройках Android.');
                if (enabled) { await post('revoke'); enabled = false; meta().content = 'available'; }
                return;
            }
            enabled = true;
            status('Регистрируем устройство…');
            await call('register');
        } catch { status('Не удалось зарегистрировать устройство. Попробуйте ещё раз.'); }
        finally { registering = false; }
    }
    async function init() {
        await listen('registration', async ({ value }) => {
            if (!enabled || !meta()) return;
            try {
                await post('register', { token: value });
                meta().content = 'enabled';
                status('Устройство зарегистрировано.');
            } catch { status('Сервер не принял регистрацию.'); }
        });
        await listen('registrationError', () => status('Firebase не зарегистрировал устройство. Проверьте сеть и конфигурацию.'));
        await listen('pushNotificationReceived', notification => event(notification, 'received'));
        await listen('pushNotificationActionPerformed', action => event(action.notification, 'opened'));
        const bind = () => {
            if (!meta()) { enabled = false; return; }
            enabled = meta().content === 'enabled';
            const on = document.getElementById('tris-push-enable');
            const off = document.getElementById('tris-push-disable');
            if (on) { on.disabled = false; on.onclick = () => register(true); }
            if (off) {
                off.disabled = false;
                off.onclick = async () => {
                    enabled = false;
                    try { await post('revoke'); meta().content = 'available'; status('Уведомления отключены на этом устройстве.'); }
                    catch { status('Не удалось отключить на сервере. Повторите попытку.'); }
                };
            }
            if (enabled) register();
            else status('Включите уведомления на этом устройстве.');
        };
        bind();
        document.addEventListener('livewire:navigated', bind);
    }
    init().catch(() => status('Не удалось инициализировать Push-плагин.'));
})();
