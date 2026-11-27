/**
 * Small, resumable binary chunks. No Livewire state and no 100 MiB multipart POST.
 * The server verifies the entire file before accepting an attachment.
 */
export const FOCUS_CHUNK_BYTES = 1024 * 1024; // New sessions: 100 MiB => 100 requests, not 200.

export function focusFileMime(file) {
    if (file?.type) return String(file.type).toLowerCase();
    const ext = String(file?.name ?? '').split('.').pop()?.toLowerCase();
    return ({
        heic: 'image/heic', heif: 'image/heif',
        jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png',
        webp: 'image/webp', mp4: 'video/mp4', mov: 'video/quicktime',
        webm: 'video/webm',
    })[ext] || '';
}

/**
 * Draft uploads can be retried while the form is editable. After sign-off,
 * only uploads already registered to that completed run are eligible.
 * Never turn an unknown local file into a new post-submit attachment.
 */
export function canRetryFocusFile(item, canEdit, responseId) {
    if (!item || item.status !== 'error' || !item.file) return false;
    if (canEdit) return true;
    return Boolean(responseId && (
        item.record?.upload_id === item.id || item.info?.upload_id === item.id
    ));
}

export function allowedFocusFile(file) {
    const mime = focusFileMime(file);
    const image = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'].includes(mime);
    const video = ['video/mp4', 'video/quicktime', 'video/webm'].includes(mime);
    if (!image && !video) return 'Поддерживаются JPEG, PNG, WebP, HEIC, MP4, MOV и WebM.';
    if (file.size < 1 || file.size > (image ? 10 : 100) * 1024 * 1024) {
        return image ? 'Фото должно быть не больше 10 МБ.' : 'Видео должно быть не больше 100 МБ.';
    }
    return '';
}

// Keep the response status, even if a proxy serves a non-JSON error page.
function setRetryAfter(error, response) {
    const value = response.headers?.get('Retry-After');
    if (!value) return;
    const seconds = /^\d+$/.test(value) ? Number(value) : (Date.parse(value) - Date.now()) / 1000;
    if (Number.isFinite(seconds)) error.retryDelay = Math.max(0, seconds * 1000);
}

export async function focusMediaRequest(url, method, body, csrf, signal, timeoutMs = 45_000) {
    const bounded = timeoutMs > 0 ? new AbortController() : null;
    if (signal?.aborted) throw new DOMException('Отменено', 'AbortError');
    const abortFromUser = () => bounded?.abort();
    let timedOut = false;
    const timeout = bounded ? setTimeout(() => {
        timedOut = true;
        bounded.abort();
    }, timeoutMs) : null;
    if (bounded) signal?.addEventListener('abort', abortFromUser, { once: true });
    try {
        const response = await fetch(url, {
            method,
            body: body === undefined ? undefined : JSON.stringify(body),
            signal: bounded?.signal ?? signal,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
            },
        });
        const result = await response.json().catch(error => {
            if (error?.name === 'AbortError' || error instanceof TypeError) throw error;
            return {};
        });
        if (!response.ok) {
            const error = new Error(result.message || 'Загрузка не удалась. Повторите попытку.');
            error.status = response.status;
            setRetryAfter(error, response);
            throw error;
        }
        return result;
    } catch (error) {
        if (timedOut && !signal?.aborted) {
            const timeoutError = new Error('Сервер не ответил вовремя. Проверьте соединение.');
            timeoutError.name = 'TimeoutError';
            throw timeoutError;
        }
        throw error;
    } finally {
        if (bounded) {
            clearTimeout(timeout);
            signal?.removeEventListener('abort', abortFromUser);
        }
    }
}

async function sendChunk(url, bytes, csrf, signal) {
    // A frozen Telegram network socket should not pin the transfer forever.
    // 45 seconds for 1 MiB still allows weak mobile connections to finish.
    const local = new AbortController();
    const abortFromUser = () => local.abort();
    if (signal?.aborted) throw new DOMException('Отменено', 'AbortError');
    signal?.addEventListener('abort', abortFromUser, { once: true });
    let timedOut = false;
    const timeout = setTimeout(() => {
        timedOut = true;
        local.abort();
    }, 45_000);
    try {
        const response = await fetch(url, {
            method: 'PUT',
            body: bytes,
            signal: local.signal,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/octet-stream',
                'X-CSRF-TOKEN': csrf,
            },
        });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            const error = new Error(data.message || 'Часть файла не загрузилась.');
            error.status = response.status;
            setRetryAfter(error, response);
            throw error;
        }
    } catch (error) {
        if (timedOut && !signal?.aborted) {
            const timeoutError = new Error('Соединение зависло. Пробуем передать часть снова.');
            timeoutError.name = 'TimeoutError';
            throw timeoutError;
        }
        throw error;
    } finally {
        clearTimeout(timeout);
        signal?.removeEventListener('abort', abortFromUser);
    }
}

/**
 * Retry only transient transport failures. Every chunk has a stable URL and
 * the backend verifies its bytes on replay; an acknowledgement lost in transit
 * must not force the user to pick the video again.
 *
 * Authorization / validation / quota failures are never retried blindly.
 */
export function isTransientFocusUploadError(error) {
    if (error?.name === 'AbortError') return false;
    if ([408, 425, 429, 500, 502, 503, 504].includes(error?.status)) return true;
    return !error?.status && (error instanceof TypeError || error?.name === 'TimeoutError');
}

async function retryFocusUpload(operation, signal, onRetry, label) {
    for (let attempt = 1; attempt <= 3; attempt++) {
        if (signal?.aborted) throw new DOMException('Отменено', 'AbortError');
        try {
            return await operation();
        } catch (error) {
            // Identify the exact failed stage without exposing file names,
            // URLs, tokens or request bodies to the UI or diagnostics.
            error.uploadStage = label;
            if (signal?.aborted || attempt === 3 || !isTransientFocusUploadError(error)) throw error;
            // Long cooldowns are handled by the outer queue, not while
            // holding an attachment revision lock.
            if (error.status === 429) throw error;
            onRetry?.({ label, attempt, maxAttempts: 3 });
            await new Promise(resolve => setTimeout(resolve, attempt === 1 ? 1000 : 3000));
        }
    }
}

// The same upload ID can be attached again after a lost receipt; the server
// returns the existing media item and current revision without adding a copy.
// Keep the caller's revision lock held across all attempts.
export function attachFocusFile(url, draft, csrf, signal, onRetry) {
    return retryFocusUpload(async () => {
        const attached = await focusMediaRequest(url, 'POST', draft, csrf, signal, 15_000);
        if (attached.media_item || attached.media) return attached;
        const error = new Error('Сервер не подтвердил прикрепление. Повторите попытку.');
        error.name = 'TimeoutError';
        throw error;
    }, signal, onRetry, 'Подтверждение файла');
}

/**
 * init is safe to call repeatedly with the same upload ID. The returned
 * received indices allow retries to skip chunks that reached the server.
 */
export async function transferFocusFile({ startUrl, file, uploadId, draft, room, question, csrf, signal, onProgress, onInit = () => {}, onRetry }) {
    const info = await retryFocusUpload(() => focusMediaRequest(startUrl, 'POST', {
        upload_id: uploadId,
        test_mode: Boolean(draft.test_mode),
        control_id: draft.control_id,
        draft_id: draft.draft_id,
        run_id: draft.run_id,
        schema_version: draft.schema_version,
        room_index: room,
        question_index: question,
        original_name: file.name,
        mime: focusFileMime(file),
        size: file.size,
    }, csrf, signal, 15_000), signal, onRetry, 'Подключение');

    onInit(info);
    if (info.status === 'attached' || info.status === 'ready') {
        onProgress(100);
        return info;
    }
    const received = new Set(info.received || []);
    const chunkBytes = info.chunk_bytes;
    if (!Number.isInteger(chunkBytes) || chunkBytes < 1 || info.total_chunks !== Math.ceil(file.size / chunkBytes)) {
        throw new Error('Сервер вернул некорректные параметры загрузки.');
    }
    onProgress(Math.round(85 * received.size / info.total_chunks));

    for (let index = 0; index < info.total_chunks; index++) {
        if (signal.aborted) throw new DOMException('Cancelled', 'AbortError');
        if (received.has(index)) continue;
        const offset = index * chunkBytes;
        const bytes = file.slice(offset, Math.min(file.size, offset + chunkBytes));
        await retryFocusUpload(() => sendChunk(info.urls.chunk_base + '/' + index, bytes, csrf, signal),
            signal, onRetry, 'Передача части ' + (index + 1));
        received.add(index);
        onProgress(Math.round(85 * received.size / info.total_chunks));
    }

    onProgress(90);
    const result = await retryFocusUpload(
        () => focusMediaRequest(info.urls.complete, 'POST', {}, csrf, signal),
        signal, onRetry, 'Завершение загрузки');
    if (result.status !== 'ready' && result.status !== 'attached') {
        throw new Error('Сервер не подтвердил готовность файла.');
    }
    onProgress(100);
    return result;
}
