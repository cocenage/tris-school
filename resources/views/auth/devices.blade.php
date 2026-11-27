<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>TRIS — Подключение устройств</title></head>
<body><main>
    <h1>Подключение устройств</h1>
    <p>Ваш TRIS ID: <strong>{{ $user->tris_id }}</strong></p>
    <p>Подтверждайте только подключения, которые вы начали самостоятельно. Введите код с нового устройства.</p>
    @forelse ($links as $link)
        <section>
            <p>Запрос от {{ $link->created_at->format('H:i') }}. Истекает в {{ $link->expires_at->format('H:i') }}.</p>
            <form action="{{ route('profile.devices.decision', $link) }}" method="POST">
                @csrf
                <label>Код нового устройства <input name="comparison_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="off"></label>
                <button name="decision" value="approve">Подтвердить</button>
                <button name="decision" value="reject" formnovalidate>Отклонить</button>
            </form>
        </section>
    @empty
        <p>Нет ожидающих запросов.</p>
    @endforelse
    <p><a href="{{ route('profile.devices') }}">Обновить</a> · <a href="{{ route('page-profile') }}">В профиль</a></p>
</main></body></html>
