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

export async function focusMediaRequest(url, method, body, csrf, signal) {
    const response = await fetch(url, {
        method,
        body: body === undefined ? undefined : JSON.stringify(body),
        signal,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf,
            ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
        },
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(result.message || 'Загрузка не удалась. Повторите попытку.');
        error.status = response.status;
        throw error;
    }
    return result;
}

async function sendChunk(url, bytes, csrf, signal) {
    const response = await fetch(url, {
        method: 'PUT',
        body: bytes,
        signal,
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
        throw error;
    }
}

/**
 * init is safe to call repeatedly with the same upload ID. The returned
 * received indices allow retries to skip chunks that reached the server.
 */
export async function transferFocusFile({ startUrl, file, uploadId, draft, room, question, csrf, signal, onProgress, onInit = () => {} }) {
    const info = await focusMediaRequest(startUrl, 'POST', {
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
    }, csrf, signal);

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
        await sendChunk(info.urls.chunk_base + '/' + index, bytes, csrf, signal);
        received.add(index);
        onProgress(Math.round(85 * received.size / info.total_chunks));
    }

    onProgress(90);
    const result = await focusMediaRequest(info.urls.complete, 'POST', {}, csrf, signal);
    if (result.status !== 'ready' && result.status !== 'attached') {
        throw new Error('Сервер не подтвердил готовность файла.');
    }
    onProgress(100);
    return result;
}
