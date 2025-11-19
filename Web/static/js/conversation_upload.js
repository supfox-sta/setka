// conversation_upload.js
// Утилиты: предпросмотр, загрузка (с прогрессом), отправка сообщений в беседу.
// Поддержка подключения как обычного <script> без type="module" — функции экспортируются в window.

(function(){

function humanFileType(file) {
    if (!file || !file.type) return 'file';
    if (file.type.startsWith('image/')) return 'image';
    if (file.type.startsWith('video/')) return 'video';
    return 'file';
}

// Upload with progress (XHR) — returns promise resolving to server JSON object {ok,type,id,url}
function uploadFileWithProgress(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.withCredentials = true;

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable && typeof onProgress === 'function') {
                onProgress(e.loaded / e.total);
            }
        };

        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                try {
                    const j = JSON.parse(xhr.responseText || '{}');
                    if (xhr.status >= 200 && xhr.status < 300 && j && j.ok) {
                        resolve(j);
                    } else {
                        reject(j || { error: 'Upload failed', status: xhr.status });
                    }
                } catch (err) {
                    reject({ error: 'Invalid JSON response', raw: xhr.responseText });
                }
            }
        };

        xhr.onerror = function() {
            reject({ error: 'Network error' });
        };

        xhr.send(formData);
    });
}

async function uploadPhoto(file, description = '', onProgress) {
    const fd = new FormData();
    fd.append('file', file);
    if (description) fd.append('description', description);
    const res = await uploadFileWithProgress('/api/upload/photo', fd, onProgress);
    return { type: res.type, id: res.id, url: res.url || null };
}

async function uploadVideo(file, name = 'Uploaded video', description = '', onProgress) {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('name', name);
    if (description) fd.append('description', description);
    const res = await uploadFileWithProgress('/api/upload/video', fd, onProgress);
    return { type: res.type, id: res.id };
}

async function uploadAudio(file, name = 'Uploaded audio', description = '', onProgress) {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('name', name);
    if (description) fd.append('description', description);
    const res = await uploadFileWithProgress('/api/upload/audio', fd, onProgress);
    return { type: res.type, id: res.id };
}

async function uploadDocument(file, name = 'Uploaded document', description = '', onProgress) {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('name', name);
    if (description) fd.append('description', description);
    const res = await uploadFileWithProgress('/api/upload/document', fd, onProgress);
    return { type: res.type, id: res.id };
}

// Send message to conversation; attachments is array of {type, id}
async function sendConversationMessage(convId, text, attachments = []) {
    const fd = new FormData();
    fd.append('conversation_id', String(convId));
    fd.append('text', text || '');
    attachments.forEach((att, idx) => {
        // PHP-friendly naming: attachments[0][type], attachments[0][id]
        fd.append(`attachments[${idx}][type]`, att.type);
        fd.append(`attachments[${idx}][id]`, String(att.id));
    });
    if (window.CSRF_HASH) fd.append('hash', window.CSRF_HASH);

    const resp = await fetch('/messenger/api/conversations/send', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
    });
    const j = await resp.json();
    if (!j) throw new Error(j.error || 'send failed');
    return j;
}

// Create conversation
async function createConversation(title, memberIds = []) {
    const fd = new FormData();
    if (title) fd.append('title', title);
    memberIds.forEach((m, idx) => fd.append(`members[${idx}]`, String(m)));
    if (window.CSRF_HASH) fd.append('hash', window.CSRF_HASH);
    const resp = await fetch('/messenger/api/create-conversation', {
        method: 'POST',
        credentials: 'same-origin',
        body: fd,
    });
    const j = await resp.json();
    if (!j.ok) throw new Error(j.error || 'create failed');
    return j.conversation;
}

// Экспорт в глобальную область видимости
window.uploadPhoto = uploadPhoto;
window.uploadVideo = uploadVideo;
window.uploadAudio = uploadAudio;
window.uploadDocument = uploadDocument;
window.sendConversationMessage = sendConversationMessage;
window.createConversation = createConversation;

})();
