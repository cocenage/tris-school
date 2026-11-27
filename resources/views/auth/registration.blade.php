<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TRIS — Анкета сотрудника</title>
</head>
<body>
    <main>
        <h1>Анкета сотрудника</h1>
        <p>Заполните все поля, чтобы отправить заявку на доступ.</p>

        @if ($errors->any())
            <ul role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form action="{{ route('access.registration.store') }}" method="POST">
            @csrf
            <p><label>Имя <input name="first_name" required maxlength="100" value="{{ old('first_name', $user->telegram_first_name) }}" autocomplete="given-name"></label></p>
            <p><label>Фамилия <input name="last_name" required maxlength="100" value="{{ old('last_name', $user->telegram_last_name) }}" autocomplete="family-name"></label></p>
            <p><label>Дата рождения <input name="birthday" type="date" required value="{{ old('birthday') }}"></label></p>
            <p><label>Телефон <input name="phone" type="tel" required placeholder="+39..." value="{{ old('phone') }}" autocomplete="tel"></label></p>
            <p><label>Должность
                <select name="requested_role" required>
                    <option value="">Выберите должность</option>
                    <option value="cleaner" @selected(old('requested_role') === 'cleaner')>Клинер</option>
                    <option value="supervisor" @selected(old('requested_role') === 'supervisor')>Супервайзер</option>
                </select>
            </label></p>
            <p><label>Город / зона <input name="city" required maxlength="120" value="{{ old('city') }}"></label></p>
            <p><label>Дата начала работы <input name="work_started_at" type="date" required value="{{ old('work_started_at') }}"></label></p>
            <p><label>Супервайзер <input name="supervisor" required maxlength="150" value="{{ old('supervisor') }}"></label></p>
            <button type="submit">Отправить заявку</button>
        </form>

        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit">Выйти</button>
        </form>
    </main>
</body>
</html>
