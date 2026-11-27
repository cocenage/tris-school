<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>TRIS — Подключение устройства</title></head>
<body><main>
    <h1>Подключить аккаунт</h1>
    @if ($errors->any())
        <ul role="alert">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    @if ($link && $link->expires_at->isFuture() && in_array($link->status, ['pending', 'approved'], true))
        <h2>Ожидание подтверждения</h2>
        <p>На основном устройстве откройте Профиль → Подключение устройств. Введите код:</p>
        <p><strong>{{ $link->comparison_code }}</strong></p>
        <p>Запрос действителен пять минут. Никому не сообщайте код, если вы не подключаете устройство сами.</p>
        <form id="claim" action="{{ route('access.connect.claim') }}" method="POST">
            @csrf
            <button type="submit">Проверить подтверждение</button>
        </form>
        <script>setTimeout(() => document.getElementById('claim')?.requestSubmit(), 5000);</script>
    @else
        @if ($link)<p>Запрос завершён или истёк. Создайте новый.</p>@endif
        <form action="{{ route('access.connect.start') }}" method="POST">
            @csrf
            <label>TRIS ID <input name="tris_id" inputmode="numeric" pattern="[1-9][0-9]{7}" maxlength="8" required autocomplete="off"></label>
            <button type="submit">Продолжить</button>
        </form>
    @endif
    <p><a href="{{ route('landing.page') }}">Вернуться ко входу</a></p>
</main></body></html>
