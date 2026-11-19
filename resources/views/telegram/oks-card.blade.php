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
        [hidden] { display: none !important; }
        .context, .hint { margin: 0; color: var(--tg-theme-hint-color, GrayText); }
        .hint { margin-bottom: .55rem; font-size: .875rem; }
        fieldset { margin: 0 0 1rem; border: 0; padding: 0; min-width: 0; }
        legend { padding: 0; margin-bottom: .45rem; font-weight: 600; }
        .choices { display: flex; flex-wrap: wrap; gap: .4rem; }
        .choice { position: relative; min-width: 0; max-width: 100%; }
        .choice-full { flex-basis: 100%; }
        .choice input { position: absolute; inset: 0; opacity: 0; height: 100%; width: 100%; margin: 0; }
        .choice span { box-sizing: border-box; display: flex; align-items: center; min-height: 44px; padding: .45rem .65rem; border: 1px solid var(--tg-theme-hint-color, GrayText); border-radius: .6rem; }
        .choice input:checked + span { background: var(--tg-theme-button-color, #176d9d); color: var(--tg-theme-button-text-color, #fff); border-color: transparent; }
        .choice input:focus-visible + span { outline: 2px solid var(--tg-theme-link-color, LinkText); outline-offset: 2px; }
        .reason { display: block; margin-top: .65rem; }
        textarea, button { box-sizing: border-box; width: 100%; border-radius: .6rem; font: inherit; }
        textarea { display: block; margin-top: .3rem; padding: .55rem; border: 1px solid var(--tg-theme-hint-color, GrayText); background: var(--tg-theme-secondary-bg-color, Field); color: var(--tg-theme-text-color, FieldText); }
        button { min-height: 44px; padding: .55rem; border: 0; background: var(--tg-theme-button-color, #176d9d); color: var(--tg-theme-button-text-color, #fff); }
        a { color: var(--tg-theme-link-color, LinkText); }
        .districts { list-style: none; margin: 0; padding: 0; }
        .districts a { display: flex; align-items: center; min-height: 44px; }
        .back { display: inline-flex; align-items: center; min-height: 44px; }
    </style>
</head>
<body>
<main>
    <p class="context">ОКС · {{ $card->scheduled_for->format('H:i') }}@if ($districtName) · {{ $districtName }}@endif</p>
    <h1>{{ $title }}</h1>
    @if ($result !== null)
        <p role="status">{{ $result }} @if (!$synced)Карточка в Telegram пока не обновилась.@endif</p>
        @if ($canChooseAnother)
            <a class="back" href="{{ route('oks.card.show', $card) }}">Выбрать другой район</a>
        @endif
        <button type="button" id="close-oks-card" hidden>Закрыть</button>
    @elseif ($district === null)
        @if ($districts === [])
            <p>Все районы отмечены.</p>
        @else
            <p id="oks-district-picker">Выбери район</p>
            <ul class="districts" aria-labelledby="oks-district-picker">
                @foreach ($districts as $key => $name)
                    <li><a href="{{ route('oks.card.show', [$card, $key]) }}">{{ $name }}</a></li>
                @endforeach
            </ul>
        @endif
    @else
        @if ($priorReason)
            <p class="hint">{{ $priorReason }}</p>
        @endif
        @foreach ($errors->all() as $error)
            <p role="alert">{{ $error }}</p>
        @endforeach
        <form method="POST" action="{{ route('oks.card.save', [$card, $district]) }}" id="oks-checklist">
            @csrf
            @foreach ($questions as $key => $question)
                @php($choice = old("answers.$key.choice", $question['selected']))
                <fieldset>
                    <legend>{{ $question['title'] }}</legend>
                    @if (!empty($question['details']))
                        <p class="hint">{{ $question['details'] }}</p>
                    @endif
                    @isset($question['version'])
                        <input type="hidden" name="answers[{{ $key }}][version]" value="{{ old("answers.$key.version", $question['version']) }}">
                    @endisset
                    <div class="choices">
                        @foreach ($question['options'] as $value => $label)
                            <label @class(['choice', 'choice-full' => $value === 'skip'])>
                                <input type="radio" name="answers[{{ $key }}][choice]" value="{{ $value }}" @checked($choice === $value) required>
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @if (isset($question['options']['note']))
                        <label class="reason" data-oks-reason @if ($choice !== 'note') hidden @endif>
                            {{ $question['prompt'] }}
                            <textarea name="answers[{{ $key }}][note]" rows="2" maxlength="420" @if ($choice === 'note') required @endif>{{ old("answers.$key.note", $question['note']) }}</textarea>
                        </label>
                    @endif
                </fieldset>
            @endforeach
            <button type="submit">Сохранить</button>
        </form>
        @if ($canChooseAnother)
            <a class="back" href="{{ route('oks.card.show', $card) }}">Выбрать другой район</a>
        @endif
    @endif
</main>
<script>
    const app = window.Telegram?.WebApp;
    app?.ready();
    document.getElementById('oks-checklist')?.addEventListener('change', event => {
        if (event.target.type !== 'radio') return;
        const reason = event.target.closest('fieldset')?.querySelector('[data-oks-reason]');
        if (!reason) return;
        reason.hidden = event.target.value !== 'note';
        reason.querySelector('textarea').required = !reason.hidden;
    });
    const close = document.getElementById('close-oks-card');
    if (app?.initData && close) {
        close.hidden = false;
        close.addEventListener('click', () => app.close());
        @if ($synced === true && !$canChooseAnother)
            app.close();
        @endif
    }
</script>
</body>
</html>
