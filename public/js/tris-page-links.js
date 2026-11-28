(() => {
    if (window.__trisPageLinks) return;
    window.__trisPageLinks = true;

    function notify(message) {
        let node = document.getElementById('tris-link-feedback');
        if (!node) {
            node = document.createElement('div');
            node.id = 'tris-link-feedback';
            node.setAttribute('role', 'status');
            node.style.cssText = 'position:fixed;bottom:calc(80px + env(safe-area-inset-bottom, 0px));left:50%;transform:translateX(-50%);max-width:90vw;z-index:9999;padding:12px 18px;border-radius:14px;background:#111;color:white;font:inherit;pointer-events:none';
            document.body.append(node);
        }
        node.textContent = message;
        clearTimeout(node._hideTimer);
        node._hideTimer = setTimeout(() => node.remove(), 2400);
    }

    document.addEventListener('click', async (event) => {
        const button = event.target.closest?.('[data-tris-copy-page]');
        if (!button) return;
        // Never include query parameters, fragments, tokens or unsaved form state.
        const url = new URL(location.pathname, location.origin).href;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(url);
            } else {
                const field = document.createElement('textarea');
                field.value = url;
                field.style.cssText = 'position:fixed;opacity:0';
                document.body.append(field);
                field.select();
                let copied = false;
                try { copied = document.execCommand('copy'); } finally { field.remove(); }
                if (!copied) throw new Error('Clipboard unavailable');
            }
            notify('Ссылка скопирована');
        } catch {
            window.prompt('Скопируйте ссылку на страницу', url);
        }
    });

    // Capacitor App handles verified HTTPS links delivered by Android.
    const cap = window.Capacitor;
    if (!cap?.isNativePlatform?.()) return;
    const app = cap.Plugins?.App;
    if (!app?.addListener || !app?.getLaunchUrl) return;

    function targetOf(raw) {
        try {
            const url = new URL(raw);
            if (url.origin !== location.origin || url.protocol !== 'https:' || url.username || url.password) return null;
            // Only accept same-origin HTTPS links; Telegram login callback is a separate host.
            return url.href;
        } catch { return null; }
    }

    function open(raw, cold = false) {
        const target = targetOf(raw);
        if (!target) return;
        if (cold) {
            try {
                const key = 'tris-consumed-launch-url';
                if (sessionStorage.getItem(key) === target) return;
                sessionStorage.setItem(key, target);
            } catch { /* storage unavailable */ }
        }
        if (target === location.href) return;
        if (cold) location.replace(target);
        else location.assign(target);
    }

    app.addListener('appUrlOpen', ({ url }) => open(url)).catch(() => {});
    app.getLaunchUrl().then((result) => open(result?.url, true)).catch(() => {});
})();
