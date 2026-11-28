<!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TRIS DEV — Push QA</title>
<x-ui.page-link-runtime />
<style>body{font:16px system-ui,sans-serif;max-width:960px;margin:auto;padding:24px;line-height:1.5}section{border-top:1px solid #ddd;padding:12px 0}button{font:inherit;padding:10px 16px;border:1px solid #aaa;border-radius:10px;background:white}small{color:#555}a{color:#274c9b}</style></head>
<body>
    <a href="{{ url('/admin') }}">← Админ-панель</a>
    <h1>Устройства и уведомления</h1>
    <button type="button" data-tris-copy-page>Скопировать ссылку</button>
    <p>TRIS DEV · Только тестовые уведомления. Статус «Принято Firebase» не гарантирует доставку.</p>
    @if(session('push_result'))<p role="status">{{ session('push_result') === 'accepted' ? 'Firebase принял сообщение' : 'Ошибка отправки. Смотрите историю.' }}</p>@endif
    <h2>Активные регистрации (за 24 часа)</h2>
    @forelse($devices as $device)
        <section>
            <strong>{{ $device->name ?? 'Сотрудник' }} · {{ $device->tris_id }}</strong>
            <p>Устройство №{{ $device->id }} · {{ $device->last_seen_at }}</p>
            <form method="post" action="{{ route('mobile.push.send') }}">@csrf
                <input type="hidden" name="device_id" value="{{ $device->id }}">
                <button type="submit">Отправить тестовый push</button>
            </form>
        </section>
    @empty
        <p>Нет зарегистрированных устройств. Включите уведомления в профиле Android-приложения.</p>
    @endforelse
    <h2>Последние отправки</h2>
    @forelse($history as $item)
        <section>
            <strong>Устройство №{{ $item->device_id }} · {{ $item->status }}</strong>
            <p>{{ $item->created_at }} @if($item->error_code) · {{ $item->error_code }} @endif</p>
            <small>Получено: {{ $item->received_at ?? 'нет подтверждения' }} · Открыто: {{ $item->opened_at ?? 'нет подтверждения' }}</small>
        </section>
    @empty
        <p>Пока нет отправок.</p>
    @endforelse
</body></html>
