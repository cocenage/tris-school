(() => {
    if (window.__trisWorkspace) return;
    window.__trisWorkspace = true;

    const context = () => document.querySelector('[data-tris-workspace]');
    function allowedPath(path, ctx) {
        if (typeof path !== 'string' || !ctx || !path.startsWith('/') || path.startsWith('//')) return false;
        try {
            const url = new URL(path, location.origin);
            if (url.origin !== location.origin || url.search || url.hash || url.pathname !== path) return false;
            const prefix = ctx.dataset.trisWorkspacePrefix;
            if (!path.startsWith(prefix + '/') || /\/(login|logout|create|edit|register|password-reset)(\/|$)/.test(path)) return false;
            if (prefix === '/admin' && /^\/admin\/(supervisor|finance)(\/|$)/.test(path)) return false;
            return true;
        } catch { return false; }
    }

    function read(ctx) {
        try {
            const raw = JSON.parse(sessionStorage.getItem('tris-workspace:' + ctx.dataset.trisWorkspace) || '{}');
            const clean = list => (Array.isArray(list) ? list : []).filter(x => x && allowedPath(x.path, ctx) && typeof x.title === 'string').slice(0, 12);
            return { favorites: clean(raw.favorites), recent: clean(raw.recent) };
        } catch { return { favorites: [], recent: [] }; }
    }

    function write(ctx, state) {
        try { sessionStorage.setItem('tris-workspace:' + ctx.dataset.trisWorkspace, JSON.stringify(state)); return true; }
        catch { return false; }
    }

    function current(ctx) {
        const path = location.pathname;
        if (!allowedPath(path, ctx)) return null;
        const title = (document.querySelector('.fi-header-heading')?.textContent?.trim() || document.title.split(' - ')[0] || path).slice(0, 150);
        return { path, title };
    }

    function updateButton(ctx, state, page) {
        const button = ctx.querySelector('[data-tris-favorite]');
        if (!button) return;
        button.hidden = !page;
        const selected = !!page && state.favorites.some(x => x.path === page.path);
        button.setAttribute('aria-pressed', String(selected));
        button.setAttribute('aria-label', selected ? 'Убрать из избранного' : 'Добавить в избранное');
        button.title = button.getAttribute('aria-label');
        button.querySelector('svg')?.setAttribute('fill', selected ? 'currentColor' : 'none');
    }

    function drawList(section, list, type, ctx) {
        if (!section) return;
        section.hidden = list.length === 0;
        const container = section.querySelector('[data-tris-' + type + '-list]');
        if (!container) return;
        container.replaceChildren();
        for (const item of list) {
            const row = document.createElement('div');
            row.className = 'flex min-w-0 items-center gap-2 px-3 py-2';
            const link = document.createElement('a');
            link.className = 'min-w-0 flex-1 break-words py-2 text-sm font-medium';
            link.href = item.path;
            link.textContent = item.title;
            row.append(link);
            if (type === 'favorites') {
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'min-h-11 min-w-11 text-gray-500';
                remove.textContent = '×';
                remove.setAttribute('aria-label', 'Убрать из избранного: ' + item.title);
                remove.addEventListener('click', () => {
                    const state = read(ctx);
                    state.favorites = state.favorites.filter(x => x.path !== item.path);
                    write(ctx, state);
                    refresh(false);
                });
                row.append(remove);
            }
            container.append(row);
        }
    }

    function refresh(track = true) {
        const ctx = context();
        if (!ctx) return;
        const page = current(ctx);
        const state = read(ctx);
        if (track && page) {
            state.recent = [page, ...state.recent.filter(x => x.path !== page.path)].slice(0, 8);
            write(ctx, state);
        }
        updateButton(ctx, state, page);
        const root = document.querySelector('[data-tris-shortcuts]');
        if (!root) return;
        root.hidden = !state.favorites.length && !state.recent.length;
        drawList(root.querySelector('[data-tris-favorites-section]'), state.favorites, 'favorites', ctx);
        drawList(root.querySelector('[data-tris-recent-section]'), state.recent, 'recent', ctx);
    }

    document.addEventListener('click', event => {
        if (!event.target.closest?.('[data-tris-favorite]')) return;
        const ctx = context();
        const page = ctx && current(ctx);
        if (!page) return;
        const state = read(ctx);
        const exists = state.favorites.some(x => x.path === page.path);
        state.favorites = exists ? state.favorites.filter(x => x.path !== page.path) : [page, ...state.favorites].slice(0, 12);
        if (write(ctx, state)) refresh(false);
    });

    document.addEventListener('livewire:navigated', () => refresh());
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => refresh(), { once: true });
    else refresh();
    // The dashboard widget may arrive after lazy Livewire hydration.
    const observer = new MutationObserver(records => {
        if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1 && (node.matches?.('[data-tris-shortcuts]') || node.querySelector?.('[data-tris-shortcuts]'))))) refresh(false);
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})();
