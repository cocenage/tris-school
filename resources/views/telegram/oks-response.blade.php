<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · ОКС</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root { color-scheme: light dark; font: 16px/1.45 system-ui, sans-serif; }
        body { margin: 0; background: var(--tg-theme-bg-color, Canvas); color: var(--tg-theme-text-color, CanvasText); }
        main { max-width: 34rem; margin: auto; padding: 1rem 1.1rem max(1rem, env(safe-area-inset-bottom)); }
        h1 { margin: .25rem 0 .8rem; font-size: 1.25rem; line-height: 1.35; }
        .district { margin: 0; color: var(--tg-theme-hint-color, GrayText); }
        label { display: block; margin-bottom: .35rem; }
        textarea, button { box-sizing: border-box; width: 100%; border-radius: .6rem; font: inherit; }
        textarea { display: block; margin-bottom: .8rem; padding: .55rem; border: 1px solid var(--tg-theme-hint-color, GrayText); background: var(--tg-theme-secondary-bg-color, Field); color: var(--tg-theme-text-color, FieldText); }
        button { min-height: 44px; padding: .55rem; border: 0; background: var(--tg-theme-button-color, #176d9d); color: var(--tg-theme-button-text-color, #fff); }
    </style>
</head>
<body>
<main>
    <p class="district">ОКС · {{ $districtName }}</p>
    <h1>{{ $title }}</h1>
    @if ($synced !== null)
        <p role="status">{{ $synced ? 'Ответ сохранён.' : 'Ответ сохранён. Карточка пока не обновилась.' }}</p>
        <button type="button" id="close-oks-app" hidden>Закрыть</button>
    @else
        <form method="POST" action="{{ $action }}">
            @csrf
            <input type="hidden" name="version" value="{{ old('version', $answer?->id ?? 0) }}">
            <label for="oks-note">{{ $question['prompt'] }}</label>
            <textarea id="oks-note" name="note" rows="3" maxlength="420" required>{{ old('note', $answer?->classification === 'problem' ? $answer->text : '') }}</textarea>
            @error('note')
                <p role="alert">{{ $message }}</p>
            @enderror
            @error('version')
                <p role="alert">{{ $message }}</p>
            @enderror
            <button type="submit">Сохранить</button>
        </form>
    @endif
</main>
<script>
    const app = window.Telegram?.WebApp;
    app?.ready();
    const closeButton = document.getElementById('close-oks-app');
    if (app?.initData && closeButton) {
        closeButton.hidden = false;
        closeButton.addEventListener('click', () => app.close());
        @if ($synced === true)
            app.close();
        @endif
    }
</script>
</body>
</html>
