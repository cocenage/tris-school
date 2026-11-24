<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · TRIS Academy</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root {color-scheme:light dark;font:16px/1.45 system-ui,sans-serif;--accent:{{ $decisionAction === 'approve' ? '#248b58' : ($decisionAction === 'reject' ? '#bb3b46' : '#3678b1') }};}
        *{box-sizing:border-box}body{margin:0;background:var(--tg-theme-bg-color,Canvas);color:var(--tg-theme-text-color,CanvasText)}
        main{max-width:34rem;margin:auto;padding:16px 18px max(20px,env(safe-area-inset-bottom))}
        header{text-align:center;margin:4px 0 18px}header .mark{margin:auto auto 10px;background:var(--accent);color:white;display:grid;place-items:center;width:48px;height:48px;border-radius:50%;font-size:24px}
        h1{font-size:22px;line-height:1.25;margin:4px 0}p{margin:6px 0;overflow-wrap:anywhere;white-space:pre-wrap}
        .hint,small{color:var(--tg-theme-hint-color,GrayText);font-size:13px}
        .card{background:var(--tg-theme-secondary-bg-color,Field);border-radius:16px;padding:14px;margin:12px 0}
        .title{font-size:14px;font-weight:600}label{display:block;margin:16px 0 6px;font-weight:600}
        textarea,button{width:100%;font:inherit;border-radius:12px}textarea{padding:12px;min-height:112px;resize:vertical;border:1px solid var(--tg-theme-hint-color,GrayText);color:var(--tg-theme-text-color,FieldText);background:var(--tg-theme-secondary-bg-color,Field)}
        button{border:0;min-height:46px;padding:10px 12px;margin:8px 0 0;font-weight:600;color:white;background:var(--accent)}
        button:disabled{opacity:.45}button.secondary{background:var(--tg-theme-secondary-bg-color,Field);color:var(--tg-theme-text-color,CanvasText)}
        [hidden]{display:none!important}.error{color:#c03948}.comment{white-space:pre-wrap;overflow-wrap:anywhere}.counts{display:flex;justify-content:space-between;gap:8px}
    </style>
</head>
<body>
<main>
    <header>
        <div class="mark" aria-hidden="true">{{ $decisionAction === 'approve' ? '✓' : ($decisionAction === 'reject' ? '×' : '◷') }}</div>
        <h1>{{ $savedComment !== null ? 'Решение сохранено' : $title }}</h1>
        <span class="hint">Вопрос по зарплате · #{{ $question->id }}</span>
    </header>
    @if ($savedComment !== null)
        <div class="card" role="status">
            <strong>{{ \App\Models\SalaryQuestion::STATUS_LABELS[$question->status] ?? $question->status }}</strong>
            <p>{{ $savedComment }}</p>
            <small>Ответ сохранён в Academy. Доставка в Telegram обрабатывается отдельно.</small>
        </div>
        <button id="close-app" type="button">Закрыть</button>
    @else
        <div class="card">
            <strong>{{ $employeeName }}</strong>
            <p class="hint">{{ $question->type }}</p>
            <p>{{ $question->comment }}</p>
            @if ($question->attachments)
                <small>Прикреплено файлов: {{ count($question->attachments) }}. Вложения можно посмотреть в исходном топике или в Academy.</small>
            @endif
            @if ($question->status === 'in_progress')
                <p class="hint">В работе · {{ $question->reviewer?->name ?: 'Ответственный руководитель' }}</p>
                <p>{{ $question->admin_comment }}</p>
            @endif
        </div>
        <form id="decision-form" method="POST" action="{{ route('salary.decision.store', [$question, $decisionAction]) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $version }}">
            <div id="editor">
                <label for="comment">{{ $prompt }}</label>
                <textarea id="comment" name="comment" maxlength="1800" rows="4" required placeholder="Напишите ответ сотруднику...">{{ old('comment') }}</textarea>
                <div class="counts"><small>Обязательное поле</small><small id="count">0 / 1800</small></div>
                @error('comment')<p class="error" role="alert">{{ $message }}</p>@enderror
                @error('version')<p class="error" role="alert">{{ $message }}</p>@enderror
                <button type="button" id="preview-button" disabled>Предпросмотр</button>
                <button type="button" class="secondary cancel">Отмена</button>
            </div>
            <div id="preview" hidden>
                <div class="card">
                    <span class="hint">Сотрудник получит</span>
                    <p><strong>{{ $button }}</strong></p>
                    <p id="preview-text" class="comment"></p>
                </div>
                <button id="confirm-button" type="submit" disabled>{{ $button }}</button>
                <button id="edit-button" type="button" class="secondary">Изменить ответ</button>
                <button type="button" class="secondary cancel">Отмена</button>
            </div>
        </form>
    @endif
</main>
<script>
    const app=window.Telegram?.WebApp;
    app?.ready();
    document.querySelectorAll('.cancel,#close-app').forEach(button=>button.addEventListener('click',()=>{if(app?.initData)app.close();else history.back();}));
    @if ($savedComment !== null)
        if(app?.initData)window.setTimeout(()=>app.close(),1000);
    @else
        const form=document.getElementById('decision-form'),input=document.getElementById('comment'),previewButton=document.getElementById('preview-button'),confirm=document.getElementById('confirm-button'),editor=document.getElementById('editor'),preview=document.getElementById('preview');
        let confirmed=false;
        function update(){document.getElementById('count').textContent=input.value.length+' / 1800';previewButton.disabled=!input.value.trim();confirmed=false;confirm.disabled=true;}
        input.addEventListener('input',update);update();
        previewButton.addEventListener('click',()=>{if(!input.reportValidity()||!input.value.trim())return;document.getElementById('preview-text').textContent=input.value.trim();editor.hidden=true;preview.hidden=false;confirmed=true;confirm.disabled=false;});
        document.getElementById('edit-button').addEventListener('click',()=>{preview.hidden=true;editor.hidden=false;update();input.focus();});
        form.addEventListener('submit',e=>{if(!confirmed||!input.value.trim()){e.preventDefault();return;}confirm.disabled=true;confirm.textContent='Сохраняем…';});
    @endif
</script>
</body>
</html>
