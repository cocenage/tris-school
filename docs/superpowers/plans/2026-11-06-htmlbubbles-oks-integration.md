# HTMLBubbles / ОКС Integration Plan

> **For agentic workers:** implement in small test-first steps; keep this experiment isolated from ordinary Telegram delivery.

**Goal:** Add a disabled-by-default HTMLBubble experiment for the Work Bot with a safe manual sender and two demo-only interactions.

**Architecture:** A dedicated beta API adapter uses the documented `/beta/bot<TOKEN>/test/<METHOD>` route without changing the existing Bot API URL. A self-contained, size-checked widget is sent only when the feature flag and an explicitly configured non-operational test chat match. Existing Work Bot update handling dispatches only `widget_query` to a separate handler; all authorization remains server-side.

**Tech Stack:** Laravel 12, PHP 8.2+, Blade, existing Laravel HTTP/cache facades, Pest; no new packages.

**Global Constraints:** No changes to scheduled messages, existing control callbacks, production configuration, working Telegram topics, or real control responses. No sends during tests. The widget remains demo-only and must fit 32 KB raw / 4 KB zlib-compressed HTML.

**Known evidence and limits:** Public Bot API 10.3 documentation does not list `sendWidget`, `answerWidgetQuery`, `editMessageWidgetData`, or `widget_query`. WidgetSandbox documents these on its experimental Bot API 10.4 beta route. The app has no authoritative per-district duty roster or current cleaning-count source; render explicit demo/unknown values and deny duty access rather than infer assignments. The widget protocol documentation does not mention `message_thread_id`, so forum-topic support remains unverified until a test-chat smoke test.

---

### Task 1: Isolate beta transport and guarded manual sender

**Files:** `config/services.php`, `app/Services/Telegram/TelegramHtmlBubblesClient.php`, `app/Console/Commands/SendTelegramHtmlBubbleTest.php`, `tests/Feature/TelegramHtmlBubblesSendTest.php`.

- Write tests first for disabled flag, missing/mismatched test chat, operational-chat rejection, safe beta URL, successful receipt, sanitized failure, and optional thread parameter.
- Implement the dedicated client, request validation, timeouts, and feature/test-target guard. Do not fall back to legacy `sendMessage`.
- Confirm tests pass with a fake HTTP API and no real sends.

### Task 2: Build the demo card and enforce payload limits

**Files:** `app/Services/Telegram/HtmlBubbles/OksWidgetBuilder.php`, `tests/Unit/OksWidgetBuilderTest.php`.

- Write tests for all five district labels, explicit demo/unknown source state, safe escaping, SDK actions, no external assets, theme switching, and raw/compressed size limits.
- Build a mobile-width single-column card with `TelegramWidget.request()`, a caption fallback, and no production roster/cleaning claims.

### Task 3: Handle test widget queries without business writes

**Files:** `app/Services/Telegram/HtmlBubbles/TelegramWidgetQueryHandler.php`, `app/Http/Controllers/TelegramWorkWebhookController.php`, `app/Services/Telegram/TelegramUpdatePoller.php`, `app/Console/Commands/TelegramWorkPollCommand.php`, targeted feature tests.

- Test `from.id` mapping, unknown users, the unconfigured duty roster, test-status authorization, duplicate/concurrent requests, isolated chat-data edit, and API failure behavior.
- Route only `widget_query` to the handler. Opt in to beta polling and its allowed update only behind the disabled-by-default flag; preserve standard polling behavior when disabled. Avoid confirming a failed or unprocessed update.

### Task 4: Manual test instructions and final checks

**Files:** `docs/telegram-htmlbubbles-oks.md` plus scoped tests.

- Document exact test-only environment keys, manual command, unsupported-client fallback, and prerequisites for actual Telegram acceptance.
- Run focused Pest tests, PHP lint, Pint on touched PHP files, and `git diff --check`.
- Do not send a real message, deploy, or push this experiment.
