import { Browser } from '@capacitor/browser';
import { Capacitor } from '@capacitor/core';
import { academyOrigin, destinations, remoteDestination, workPath } from './routing.mjs';
import './style.css';

const status = document.querySelector('#status');
const remote = document.querySelector('#remote');
let origin = null;
try {
    origin = academyOrigin(import.meta.env.VITE_ACADEMY_ORIGIN);
} catch (error) {
    status.textContent = error.message;
}
remote.disabled = !origin;
document.querySelector('#target').textContent = origin ?? 'Локальная проверка. Сервер Академии не настроен.';

async function openWorkLink(value) {
    try {
        const path = workPath(value, origin ?? undefined);
        if (!remote.checked) {
            document.querySelector('#fixture').hidden = false;
            document.querySelector('#route').textContent = path;
            status.textContent = 'Маршрут проверен локально.';
            return;
        }
        const url = remoteDestination(value, { origin, enabled: remote.checked });
        if (Capacitor.isNativePlatform()) {
            await Browser.open({ url, toolbarColor: '#145446' });
        } else {
            window.open(url, '_blank', 'noopener,noreferrer');
        }
        status.textContent = 'Запрошено открытие Академии. Войди через Telegram в открывшемся окне.';
    } catch (error) {
        status.textContent = error.message;
    }
}

for (const destination of destinations) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = destination.label;
    button.addEventListener('click', () => openWorkLink(destination.path));
    document.querySelector('#navigation').append(button);
}

document.querySelector('#link-form').addEventListener('submit', (event) => {
    event.preventDefault();
    openWorkLink(document.querySelector('#work-link').value.trim());
});
