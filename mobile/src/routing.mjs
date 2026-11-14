export const destinations = [
    { label: 'Главная', path: '/home' },
    { label: 'Заявки', path: '/applications' },
    { label: 'Начать контроль', path: '/checks/control' },
    { label: 'Focus Control', path: '/checks/control/focus' },
    { label: 'Мои контроли', path: '/profile/checks' },
    { label: 'Квартиры', path: '/apartments' },
    { label: 'Инструкции', path: '/home/instructions' },
    { label: 'Календарь', path: '/profile/calendar' },
    { label: 'Профиль', path: '/profile' },
];

const additionalPaths = [
    '/login', '/applications/weekend', '/applications/vacation',
    '/applications/inventory', '/profile/applications',
];
const paths = new Set([...destinations.map(({ path }) => path), ...additionalPaths]);

export function academyOrigin(value) {
    if (!value) return null;
    const url = new URL(value);
    if (url.protocol !== 'https:' || url.username || url.password ||
        url.pathname !== '/' || url.search || url.hash) {
        throw new Error('Укажи HTTPS origin Академии без пути и параметров.');
    }
    return url.origin;
}

export function workPath(value, origin = 'https://fixture.invalid') {
    // Reject URL normalization tricks before parsing.
    if (!value || /[\\\s%]/u.test(value) || value.includes('/./') || value.includes('/../')) {
        throw new Error('Недопустимая рабочая ссылка.');
    }
    const url = new URL(value, origin);
    const knownPath = paths.has(url.pathname) ||
        /^\/(?:profile\/checks|apartments)\/[1-9]\d*$/.test(url.pathname);
    if (url.origin !== origin || url.username || url.password || url.search || url.hash || !knownPath) {
        throw new Error('Разрешены только рабочие разделы настроенной Академии.');
    }
    return url.pathname;
}

export function remoteDestination(value, { origin, enabled }) {
    if (!enabled || !origin) throw new Error('Сначала включи открытие настроенной Академии.');
    return new URL(workPath(value, origin), origin).href;
}
