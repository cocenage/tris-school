# TRIS Academy — план UI-рефакторинга

Статус: безопасный план без изменений рабочих страниц. Все действия ниже относятся к представлению, CSS и переиспользованию компонентов; маршруты, Livewire, запросы, права, база и внешние интеграции остаются неизменными.

## 1. Карта интерфейса

### Пользовательский shell

`resources/views/layouts/app.blade.php` подключает Vite и Livewire, ограничивает мобильный shell шириной 768 px, выводит header, прокручиваемый main, глобальный toast и нижнюю навигацию. Навигация находится в `resources/views/components/partials/⚡navbar.blade.php` и содержит пять разделов: главная, проверки, задачи, заявки и профиль. Header и navbar используют собственные hardcoded-классы и inline SVG-градиент.

### Страницы и панели

| Раздел | Страницы | Основные UI-объекты |
|---|---|---|
| Вход и доступ | landing, pending, rejected, required | login-карточки, Telegram action, состояния доступа, toast |
| Главная | `page-home`, инструкции, статья | search header, горизонтальные карточки, статья, TOC, image modal, notes/FAQ |
| Проверки | `page-checks`, control, coaching, profile checks | плитки, формы контроля, загрузка фото, autosave, review sheet, статусы |
| Заявки | `page-applications`, weekend, vacation, inventory, salary, schedule, feedback | плитки, формы, textarea, select/dropdown, attachments, quantity controls, success sheet |
| Профиль | `page-profile`, profile checks/all-checks/check-result/applications | avatar/header, TRIS Mare progress card, списки, фильтры, status badges, bottom sheet |
| Задачи | tasks, board, calendar, rooms, room, task | boards/columns, task cards, calendar, comments/checklist, create/edit/move sheets |
| Администрирование | Filament admin, education, finance | sidebar, ресурсы, tables, filters, forms, widgets, notifications |

### Общие элементы

- Layout: `layouts/app.blade.php`.
- Header/navbar: `components/partials/⚡header.blade.php`, `⚡navbar.blade.php`.
- Primitives: `components/ui/button.blade.php`, `modal.blade.php`, `bottom-sheet.blade.php`, `toast.blade.php`, `guide.blade.php`, `protected-content.blade.php`.
- Search: `components/search/⚡search-bar.blade.php`.
- Формы: семь Livewire/Volt-экранов в `resources/views/components/forms/`.
- Модальные состояния: modal, bottom sheet, guide, image modal статьи, профильный custom sheet и локальные sheets задач.
- Уведомления: глобальный `x-ui.toast`, success sheets форм, Livewire dispatch и отдельные Filament notifications.

## 2. Найденные визуальные несоответствия

### Цвета и поверхности

В шаблонах найдено более тысячи arbitrary background-классов. Наиболее частые значения: `#F8F8F8`, `#E1E1E1`, `#F6F6F6`, `#F4F7FB`, `#7D7D7D`, а рядом встречаются `#F7F7F5`, `#E2E2E2`, `#EEF3F8`, `#FEE4E2` и десятки одноразовых цветов. Токены в `resources/css/app.css` определены, но большинство экранов их не использует.

Пользовательская часть и три Filament-панели образуют две независимые дизайн-системы: mobile использует Akt, нейтральные поверхности и синий градиент, а Filament — Sky/Slate, Amber/Zinc, Emerald/Rose и свои стандартные компоненты.

### Типографика, отступы и радиусы

Глобальные `h1/h2/p` задают 32/18/16 px, но локальные шаблоны используют диапазон примерно 11–32 px, различные `font-medium/semibold/bold`, отступы 10–32 px и радиусы от 18 до 50 px. Это заметно в profile, forms, tasks и article.

### Кнопки и формы

`x-ui.button` используется примерно 56 раз и имеет только `primary/secondary`; параллельно есть прямые `<button>` с высотой 36–54 px, разными границами и цветами. В нём есть inline progress/hover-стили, поэтому любое изменение компонента нужно делать отдельно и с визуальной проверкой.

Формы повторяют header, back/search-кнопки, textarea и attachment-блоки, но без единого Field/Input/Select primitive. `page-control` имеет сложный autosave/review и локальный `<style>`, поэтому его нельзя брать первым экраном для рефакторинга.

### Карточки, таблицы и статусы

Плитки главной/заявок используют разные варианты серого и разные композиции. Профиль применяет тёплые поверхности, задачи — собственные deadline/status правила. Мобильные списки — это карточки, Filament — полноценные таблицы; общей спецификации column/filter/empty/loading нет.

Статусы заявок уже имеют полезную семантическую карту (success/warning/danger/info), но она дублируется по страницам. Проверки используют дополнительные точки/зоны, задачи — свои badge, Filament — собственные color enum.

### Модальные окна, уведомления и inline CSS

`modal`, `bottom-sheet`, `guide`, профильный sheet и image modal статьи частично дублируют overlay, radius и z-index. В `page-calendar` около 158 hex-значений и 22 inline-style, в `page-control` около 130 hex-значений; локальные `<style>` есть в control, article и protected-content. Это главные hotspots для последующих малых изменений.

### Мобильная версия и доступность

Мобильный shell и нижняя навигация — хорошая основа, но вложенные overflow, фиксированные высоты, горизонтальные карусели и крупный календарь требуют проверки на малых экранах. У icon-only кнопок и цветовых статусов нужно системно проверить `aria-label`, focus-visible, disabled/loading и достаточный touch target. `protected-content` блокирует выделение, печать и контекстное меню; это отдельное решение безопасности, а не универсальный UI-паттерн.

## 3. Лучшие основы стиля

1. `resources/views/components/⚡page-profile.blade.php` — наиболее полная иерархия: header, avatar, progress card, списки, действия и bottom sheet. Подходит как эталон плотности, карточек и вторичного текста.
2. `resources/views/components/⚡page-home.blade.php` — простой экран с поисковым header и горизонтальными instructional cards. Удобен для первого визуального эксперимента без сложной формы.
3. `resources/views/components/profile/⚡page-applications.blade.php` — хороший пример списка заявок, empty/list состояний и семантических status badges. Его цветовую карту стоит вынести в общий primitive.

`tasks/⚡page-calendar.blade.php`, `forms/⚡page-control.blade.php` и статья не являются стартовыми эталонами: они слишком велики, содержат много локальной стилизации и имеют более высокий риск побочного изменения.

## 4. Целевые переиспользуемые компоненты

Предлагаемые новые или расширяемые компоненты в `resources/views/components/ui/`:

| Компонент | Предлагаемый API | Назначение |
|---|---|---|
| `button` | `variant`, `size`, `type`, `loading`, `disabled`, `href` | расширить существующий без смены обработчиков |
| `field` | `label`, `hint`, `error`, `required` | единая оболочка поля |
| `input` / `textarea` | `wire:model`, `type`, `placeholder`, `error` | размеры, focus и состояния |
| `select` | `options`, `placeholder`, `error` | единый select/dropdown |
| `card` | `tone`, `padding`, `as` | поверхность и radius |
| `table` | `columns`, `empty`, `loading` | только presentation; на mobile — list fallback |
| `status-badge` | `status`, `label`, `icon` | success/warning/danger/info |
| `empty-state` | `title`, `description`, `action` | отсутствие данных |
| `alert` | `tone`, `title`, `dismissible` | заметный контекст |
| `page-header` | `title`, `back`, `search`, `actions` | общий header экранов |
| `icon-button` | `label`, `variant`, `size` | доступная icon-only кнопка |

Существующие `modal`, `bottom-sheet` и `toast` следует композиционно использовать, а не переписывать в каждой странице. API новых компонентов проектируется так, чтобы Livewire `wire:model`, `wire:click`, slots и существующие submit-обработчики не менялись.

## 5. Порядок постепенной переделки

### Этап 0 — baseline

Снять screenshots/DOM-снимки доступных экранов, зафиксировать текущие состояния empty/loading/error и составить таблицу route → view → component. Не запускать миграции, очереди, scheduler, Telegram или почту.

### Этап 1 — токены и primitives

После отдельного разрешения добавить семантические CSS-токены, затем стабилизировать `button`, `page-header`, `field`, `input`, `select`, `card`, `status-badge`, `empty-state` и `alert`. На этом шаге не менять содержимое запросов и обработчиков.

### Этап 2 — один простой экран

Визуально перевести только `page-applications` или `page-home`: page header, плитки, отступы, радиусы и empty state. Сравнить desktop/mobile и убедиться, что маршруты и данные те же.

### Этап 3 — простые формы

Начать с feedback, salary и schedule, где меньше сложных ветвлений. Затем перейти к vacation, weekend и inventory. Общий form header и поля заменить primitives, оставив `wire:submit`, валидацию, upload и success sheet.

### Этап 4 — профиль и проверки

Унифицировать status badges, tabs, filter controls, списки и карточки profile checks/applications. `page-profile` использовать как визуальную базу, не меняя расчёты прогресса и права.

### Этап 5 — сложные задачи

Последовательно обработать tasks, board, rooms и task detail. Календарь оставить последним пользовательским экраном из-за размера, inline styles и множества modal/sheet состояний.

### Этап 6 — статья и общие overlay

Вынести article typography и image modal в tokens/primitives; затем свести modal, guide и bottom-sheet к общей оболочке. `protected-content` пересмотреть отдельно как security/UX решение.

### Этап 7 — Filament

После стабилизации mobile UI постепенно настроить семантические цвета Filament admin/education/finance. Ресурсные таблицы, filters, actions и права не менять; только тема и presentation.

### Этап 8 — проверка качества

Для каждого этапа проверить 360 px, 768 px и широкий экран, keyboard focus, touch target, reduced motion, empty/loading/error. В отдельном diff убедиться, что изменены только Blade/CSS/компоненты текущего этапа.

## 6. Ограничения и критерии безопасности

- не менять `.env`, миграции, сидеры, таблицы, модели и бизнес-правила;
- не менять маршруты, Livewire methods, queries, policies, services и события;
- не запускать queue, scheduler, Telegram, mail или внешние API;
- не удалять неработающий без хостинговых данных код;
- один экран или один primitive на небольшой коммит;
- до и после — одинаковый URL, права, submit и payload;
- проверять UI на fixtures/empty state, не требуя копии production-базы.

## 7. Небольшая первая UI-задача

После отдельного разрешения реализовать только `x-ui.page-header` и заменить им заголовок на статической странице `resources/views/components/⚡page-applications.blade.php`. Это ограниченный presentation-only diff: не затрагивает базу, формы и Telegram, а проверяется открытием `/applications` в Herd на пустом или тестовом локальном состоянии. Если результат устраивает, тем же primitive можно постепенно обработать формы.

До такого разрешения в проекте созданы только этот план и `DESIGN.md`; рабочие страницы не редактируются.

