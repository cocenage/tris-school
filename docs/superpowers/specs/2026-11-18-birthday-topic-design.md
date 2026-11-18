# Birthday Messages in the Forum Topic

## Intent

Send each staff birthday greeting to the configured celebrations forum topic on the birthday itself. Keep birthday greetings out of the regular event digest and avoid empty posts.

## Goals and constraints

- Reuse `chat_id_celebrations`, `thread_id_celebrations`, the Work Bot, and the existing outbound journal.
- Require both the dedicated chat and a valid topic ID; never fall back to the forum root or work-shift chat.
- Send one message per birthday: `🎂 С днём рождения, {name}!` with the image for the birthday month (`01` through `12`).
- Look up birthdays for the current day in `Europe/Rome`, while keeping the existing next-day calendar digest for other event types.
- Keep work-shift summaries and non-birthday calendar events unchanged.
- Send no post when there are no birthdays for today and no non-birthday celebration events for tomorrow.
- Preserve idempotency and do not retry an uncertain Telegram delivery automatically.

## Approaches

1. **Extend the current celebration command (chosen).** Split birthday events from the grouped event digest, send each birthday through the existing forum-topic route and event key, and retain the existing journal. This keeps routing and duplicate protection in one place.
2. **Add a new birthday command and schedule.** This isolates the flow but duplicates date selection, routing, and delivery safeguards.
3. **Keep birthdays in the grouped digest and add a second greeting.** This would duplicate the greeting and still leave it in the digest the user wants separated.

The existing command and route already support a dedicated group topic, so option 1 is the smallest coherent change. A new bot, private messages, and production configuration changes are out of scope.

## Open implementation detail

The card directory is `resources/images/team-celebration-cards`. It contains the user's twelve JPG cards, named `1.jpg` through `12.jpg`; the code also accepts zero-padded names and JPEG/PNG extensions.

If the card for a birthday month is missing or unreadable, skip that birthday send and report the missing card. Do not send a text-only greeting that would omit the requested image.
