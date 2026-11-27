import { Capacitor } from '@capacitor/core';
import { PushNotifications } from '@capacitor/push-notifications';

const byId = (id) => document.getElementById(id);
const setStatus = (value) => { byId('status').textContent = value; };
function log(event, detail = '') {
    const line = new Date().toLocaleTimeString() + '  ' + event + (detail ? ': ' + detail : '');
    byId('log').textContent = (line + '\n' + byId('log').textContent).slice(0, 16000);
}
function failure(error) {
    setStatus('Ошибка');
    log('Ошибка', String(error?.message ?? error?.error ?? error));
}
async function init() {
    if (Capacitor.getPlatform() !== 'android') {
        setStatus('Откройте Android APK, а не браузер.');
        return;
    }
    await PushNotifications.addListener('registration', ({ value }) => {
        byId('token').value = value;
        byId('copy').disabled = false;
        setStatus('FCM: токен получен');
        log('registration', 'Токен получен, значение скрыто из журнала');
    });
    await PushNotifications.addListener('registrationError', failure);
    await PushNotifications.addListener('pushNotificationReceived', notification => {
        log('Получено в приложении', JSON.stringify({ id: notification.id, title: notification.title, body: notification.body }));
    });
    await PushNotifications.addListener('pushNotificationActionPerformed', action => {
        log('Нажатие на уведомление', JSON.stringify({ id: action.notification.id, title: action.notification.title, action: action.actionId }));
    });
    byId('register').disabled = false;
    byId('delivered').disabled = false;
    const status = await PushNotifications.checkPermissions();
    setStatus('Разрешение: ' + status.receive);
    byId('register').onclick = async () => {
        try {
            let perm = await PushNotifications.checkPermissions();
            if (perm.receive === 'prompt' || perm.receive === 'prompt-with-rationale') {
                perm = await PushNotifications.requestPermissions();
            }
            if (perm.receive !== 'granted') {
                setStatus('Уведомления запрещены. Разрешите их в настройках Android.');
                return;
            }
            setStatus('Регистрация FCM…');
            await PushNotifications.register();
        } catch (error) { failure(error); }
    };
    byId('delivered').onclick = async () => {
        try {
            const { notifications } = await PushNotifications.getDeliveredNotifications();
            log('Уведомлений в шторке', String(notifications.length));
        } catch (error) { failure(error); }
    };
    byId('copy').onclick = async () => {
        try { await navigator.clipboard.writeText(byId('token').value); log('Токен скопирован'); }
        catch { byId('token').focus(); byId('token').select(); log('Выделите и скопируйте токен вручную'); }
    };
    byId('clear').onclick = () => { byId('log').textContent = ''; };
}
init().catch(failure);
