<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('mobile.push-bridge')
    <title>TRIS — Уведомления</title>
    <style>body{font:16px system-ui,sans-serif;margin:auto;max-width:640px;padding:24px;line-height:1.5}button{font:inherit;padding:12px 16px;margin:5px 0;border:1px solid #aaa;border-radius:12px;background:#fff;color:#111}section{border-top:1px solid #ddd;padding:14px 0}small{color:#555}a{color:#274c9b}</style>
</head>
<body>
    <a href="{{ route('page-profile') }}">← Профиль</a>
    <h1>Уведомления</h1>
    <small>TRIS DEV · PUSH 0.1</small>
    <p id="tris-push-status" role="status">Проверяем Android…</p>
    <button id="tris-push-enable" type="button" disabled>Включить на этом устройстве</button>
    <button id="tris-push-disable" type="button" disabled>Отключить на этом устройстве</button>
    <p><a href="{{ route('mobile.push') }}">Обновить историю</a></p>
    <h2>История</h2>
    @forelse($history as $item)
        <section>
            <strong>Тестовое уведомление</strong>
            <p>{{ $item->created_at }} · {{ ['pending'=>'В обработке','accepted'=>'Принято Firebase','failed'=>'Ошибка отправки'][$item->status] ?? $item->status }}</p>
            @if($item->received_at)<small>Получено в приложении: {{ $item->received_at }}</small><br>@endif
            @if($item->opened_at)<small>Открыто: {{ $item->opened_at }}</small>@endif
        </section>
    @empty
        <p>Пока нет уведомлений.</p>
    @endforelse
</body></html>
