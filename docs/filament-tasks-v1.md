# Tasks in Filament

Existing tasks schema retained. Admin panel /admin/tasks provides list, creation and edit, assignment to active approved staff, dates, priority and status. No migrations or deletion.

New assignments use a separate existing-model room and board "Персональные поручения" with no room membership: direct assignment grants access. Do not add ordinary employees to this room through legacy room management. Existing tasks retain their rooms and legacy visibility. Old client-side management and broad supervisor/room permissions remain for now; supervisor admin access requires a separate authorization review. No new Telegram or push sends on Filament assignment yet. Pulse reads the same tasks.

Manual acceptance: active approved admin creates a task for a cleaner with today's deadline; check assignee columns/pivot, cleaner task list and Pulse; another cleaner must not see the personal task; change deadline or complete task; verify Pulse; verify non-admin cannot access resource.