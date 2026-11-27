# Native Telegram login — DEV only

Official Android SDK 1.0.0: https://github.com/TelegramMessenger/telegram-login-android
Token validation: https://core.telegram.org/bots/telegram-login

The server endpoints are POST /telegram/native/start and POST /telegram/native/complete (same-origin web session, CSRF required, JSON only). Disabled unless APP_ENV=mobiledev or testing and TRIS_NATIVE_TELEGRAM_ENABLED=true. Configure TRIS_NATIVE_TELEGRAM_CLIENT_ID to the registered test bot's public Client ID, install the verifier with composer install --working-dir=tools/mobile/telegram-auth and apply the migration to the isolated DEV database. No client secret or bot token is required for verifying the SDK's signed ID token.

Start returns an attempt and client_id. The native SDK must request the profile scope and return the ID token via a verified HTTPS Android App Link. Complete accepts attempt and id_token; the server verifies RS256 via Telegram's fixed JWKS, issuer, configured audience, expiration, issuance time (120 seconds), and signed numeric id (not OIDC sub). A database uniqueness constraint prevents reuse; tokens are not persisted. The same existing TelegramAuthService resolves users, preserves their TRIS ID, creates new accounts with status new, and returns a route for their status.

The SDK's current source implements PKCE but does not expose a nonce for server challenge binding. The session attempt and replay table are not equivalent to a token-bound nonce: stolen fresh bearer tokens remain a risk. Do not enable in production or expose the DEV server. Production requires a reviewed challenge-bound authentication protocol and transport/session revocation. The Android integration uses the pinned upstream source (MIT license) and a Gradle wrapper module instead of GitHub Packages, so no GitHub PAT is needed. Existing web and Mini App login paths are unchanged.

Run from the existing local Android worktree:
```powershell
git fetch origin
git merge origin/feature/tris-1.0-telegram-native
powershell.exe -NoProfile -File tools/mobile/Start-Dev.ps1 -NativeTelegram
```
This enables the server endpoints, creates the ignored DEV config with public Client ID 8262627457, installs PHP JWT verification, copies the SDK and native bridge, adds the HTTPS App Link https://app8262627457-login.tg.dev/tglogin, and builds/installs the USB-only APK. The installer refuses to replace a customized MainActivity. SDK files and build changes are not reverted automatically; only the Capacitor server config is restored as before. The login bridge only accepts calls from the DEV login page on loopback port 8765 and checks the exact HTTPS callback. After installation sign out of the TRIS ID session to see the native login button. New Telegram accounts should reach the registration form. This isolated server intentionally has no real bot token: admin Telegram notification delivery is not tested here. Check app link association using adb shell pm get-app-links eu.trisservice.academy.dev. If the process is killed during login, restart the attempt. Do not distribute this APK. Run NativeTelegramLoginTest and NativeTelegramTokenVerifierTest separately under the isolated Pest environment. Actual compile, provider callback and tests remain to be verified on the user's machine.
