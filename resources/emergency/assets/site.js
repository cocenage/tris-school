(function () {
    'use strict';

    const snapshotNode = document.getElementById('emergency-snapshot');
    const app = JSON.parse(snapshotNode.textContent || '{}');
    const draftKey = 'emergency-tris-feedback-draft-v1';
    const reportKey = 'emergency-tris-feedback-pending-v1';
    const form = document.getElementById('feedback-form');
    const statusNode = document.getElementById('feedback-status');
    const copyButton = document.getElementById('copy-report');
    let pendingReport = null;

    function node(tag, text, className) {
        const element = document.createElement(tag);
        if (text !== undefined && text !== null && text !== '') element.textContent = String(text);
        if (className) element.className = className;
        return element;
    }

    function addParagraph(parent, text) {
        if (text) parent.append(node('p', text));
    }

    function searchableText(value) {
        if (typeof value === 'string') return value;
        if (Array.isArray(value)) return value.map(searchableText).join(' ');
        if (value && typeof value === 'object') return Object.values(value).map(searchableText).join(' ');
        return '';
    }

    function renderBlock(block) {
        const section = node('section', null, 'instruction-block');
        if (block.title) section.append(node('h4', block.title));

        if (block.type === 'hero' || block.type === 'text') {
            addParagraph(section, block.description || block.content || '');
            if (block.badge) section.append(node('p', block.badge, 'eyebrow'));
        } else if (block.type === 'warning') {
            section.classList.add('warning-block', block.style || 'warning');
            addParagraph(section, block.content);
        } else if (['steps', 'checklist', 'tips'].includes(block.type)) {
            const list = node(block.type === 'steps' ? 'ol' : 'ul');
            (block.items || []).forEach((item) => {
                const li = node('li');
                if (item.title) li.append(node('strong', item.title));
                if (item.text) {
                    if (item.title) li.append(document.createTextNode(' — '));
                    li.append(document.createTextNode(item.text));
                }
                list.append(li);
            });
            section.append(list);
        } else if (block.type === 'faq') {
            (block.items || []).forEach((item) => {
                const details = node('details');
                details.append(node('summary', item.question));
                addParagraph(details, item.answer);
                section.append(details);
            });
        } else if (block.type === 'links') {
            (block.items || []).forEach((item) => {
                const link = node('a', item.label);
                link.href = item.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                section.append(link, document.createTextNode(' '));
            });
        }

        return section;
    }

    function renderInstructions(query) {
        const host = document.getElementById('instructions');
        host.replaceChildren();
        const needle = (query || '').trim().toLocaleLowerCase('ru');
        let visible = 0;

        (app.instructions || []).forEach((group) => {
            const matches = (group.instructions || []).filter((item) => {
                const text = searchableText([item.title, item.short_description, item.blocks]).toLocaleLowerCase('ru');
                return !needle || text.includes(needle);
            });
            if (!matches.length) return;

            const section = node('section', null, 'category');
            section.append(node('h3', group.title));
            matches.forEach((item) => {
                visible += 1;
                const details = node('details', null, 'instruction-card');
                details.append(node('summary', item.title));
                if (item.short_description) details.append(node('p', item.short_description, 'instruction-description'));
                const content = node('div', null, 'instruction-content');
                (item.blocks || []).forEach((block) => content.append(renderBlock(block)));
                if (!content.childElementCount) content.append(node('p', 'Содержание инструкции отсутствует.'));
                details.append(content);
                section.append(details);
            });
            host.append(section);
        });

        document.getElementById('instructions-empty').hidden = visible > 0;
    }

    function fillSelect(id, options) {
        const select = document.getElementById(id);
        (options || []).forEach((option) => {
            const element = node('option', option.label);
            element.value = option.value;
            select.append(element);
        });
    }

    function currentFields() {
        return Object.fromEntries(new FormData(form).entries());
    }

    function saveDraft() {
        try { localStorage.setItem(draftKey, JSON.stringify(currentFields())); } catch (_) { /* Browser storage may be disabled. */ }
    }

    function buildReport() {
        const fields = currentFields();
        return {
            submission_id: (window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`),
            submitted_at: new Date().toISOString(),
            snapshot_id: app.snapshot_id,
            type: fields.type || '',
            area: fields.area || '',
            urgency: fields.urgency || '',
            district: fields.district || '',
            apartment: fields.apartment || '',
            name: fields.name || '',
            reference: fields.reference || '',
            message: fields.message || '',
        };
    }

    async function copyReport() {
        if (!form.reportValidity()) return;
        pendingReport = pendingReport || buildReport();
        const payload = JSON.stringify(pendingReport, null, 2);
        try {
            await navigator.clipboard.writeText(payload);
        } catch (_) {
            const temporary = node('textarea');
            temporary.value = payload;
            temporary.style.position = 'fixed';
            temporary.style.opacity = '0';
            document.body.append(temporary);
            temporary.select();
            document.execCommand('copy');
            temporary.remove();
        }
        statusNode.textContent = 'JSON отчёта скопирован. Это не означает, что отчёт отправлен.';
    }

    document.getElementById('source').textContent = app.source || 'TRIS Academy';
    document.getElementById('status-title').textContent = app.status?.title || '⚠ TRIS — аварийный режим';
    document.getElementById('status-message').textContent = app.status?.message || '';
    document.getElementById('updated-label').textContent = app.status?.last_updated_label || 'Данные актуальны на:';
    const generated = new Date(app.generated_at);
    document.getElementById('updated-at').textContent = Number.isNaN(generated.getTime())
        ? 'время неизвестно'
        : new Intl.DateTimeFormat('ru-RU', { timeZone: app.timezone, dateStyle: 'short', timeStyle: 'short' }).format(generated);

    const ageHours = (Date.now() - generated.getTime()) / 3600000;
    const freshness = document.getElementById('freshness');
    if (Number.isFinite(ageHours) && ageHours >= (app.freshness?.critical_hours || 48)) {
        freshness.classList.add('is-critical');
        freshness.textContent = 'Снимок существенно устарел. Инструкции остаются доступны, но уточните актуальность.';
    } else if (!Number.isFinite(ageHours) || ageHours >= (app.freshness?.warning_hours || 12)) {
        freshness.classList.add('is-warning');
        freshness.textContent = 'Снимок давно не обновлялся. Инструкции остаются доступны.';
    } else {
        freshness.textContent = 'Снимок недавно обновлён.';
    }

    fillSelect('feedback-type', app.feedback?.types);
    fillSelect('feedback-area', app.feedback?.areas);
    fillSelect('feedback-urgency', app.feedback?.urgencies);
    renderInstructions('');

    document.getElementById('instruction-search').addEventListener('input', (event) => renderInstructions(event.target.value));
    form.addEventListener('input', saveDraft);
    try {
        const draft = JSON.parse(localStorage.getItem(draftKey) || '{}');
        Object.entries(draft).forEach(([key, value]) => {
            const field = form.elements.namedItem(key);
            if (field) field.value = value;
        });
        if (draft.message) statusNode.textContent = 'Сохранён черновик. Отчёт ещё не отправлен.';
    } catch (_) { /* Ignore malformed or unavailable local storage. */ }

    copyButton.addEventListener('click', copyReport);
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        pendingReport = buildReport();
        try { localStorage.setItem(reportKey, JSON.stringify(pendingReport)); } catch (_) { /* The form stays usable without storage. */ }

        if (!app.feedback?.endpoint) {
            statusNode.textContent = 'Адрес отправки не настроен. Отчёт не отправлен; черновик сохранён. Скопируйте JSON и передайте его ответственному.';
            return;
        }

        const submitButton = form.querySelector('[type="submit"]');
        submitButton.disabled = true;
        statusNode.textContent = 'Отправляем отчёт…';
        try {
            const response = await fetch(app.feedback.endpoint, {
                method: 'POST',
                mode: 'cors',
                credentials: 'omit',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(pendingReport),
            });
            if (!response.ok) throw new Error('Feedback endpoint returned a failure response.');
            localStorage.removeItem(draftKey);
            localStorage.removeItem(reportKey);
            form.reset();
            pendingReport = null;
            statusNode.textContent = 'Независимый сервис подтвердил приём отчёта.';
        } catch (_) {
            statusNode.textContent = 'Не удалось подтвердить отправку. Отчёт не считается отправленным; черновик сохранён. Скопируйте JSON для ручной передачи.';
        } finally {
            submitButton.disabled = false;
        }
    });
}());
