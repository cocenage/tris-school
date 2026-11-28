# TRIS 1.0 unified Android DEV

Use feature/tris-1.0-unified-dev as the phone build branch. Integrated source snapshots:
- Pulse backend: e9129835c74accacd4a97d175f6b4c23cecce0b2; home Vue island: 2dd42b06f3f1bf1e1f4678a8b97f8f2ce1426ecf.
- Admin UI: 9dca3c04980442069afdd5d888cea670eb3c562b (merged).
- Request UI: b63af363f6810a511f7b2b28ad4ad3a9885fe104 (content reconciled, not a history merge). Latest request view and category filters retained; canonical /applications, legacy redirect, badge, six creation routes and creation sheet retained. Creation uses the same animated dialog event as filters; filter state listens to its own sheet.

For USB DEV: stop the running Laravel server, switch to unified branch and fast-forward, then run tools/mobile/Start-Dev.ps1 -NativeTelegram. Never reset the local untracked Android project or DEV database. No production deployment. Verify Pulse, requests, creation, attachment permissions, admin UI and auth on a connected phone. Native auth security limitations remain DEV-only. Runtime tests must be run locally.
