# TRIS Android USB integration (DEV only)

This is an integration aid for the already generated local Android project, not a replacement for the future packaged Vue API client. It uses Capacitor's development server URL over ADB reverse and the current Laravel web routes, including the TRIS ID flow. No public tunnel or production credentials are needed.

From the existing Android worktree, merge the integration branch and start a separate PowerShell process:

```powershell
git fetch origin
git merge origin/feature/tris-1.0-mobile-integration
powershell.exe -NoProfile -File tools/mobile/Start-Dev.ps1
```

The script requires the local Capacitor JSON configuration, JDK 21, SDK 36, one connected USB device, and installed Composer dependencies. It creates an isolated .env.mobiledev and dedicated SQLite files under storage/app/mobile-dev; it refuses unsafe database configuration. It compiles Laravel assets, runs migrations (analytics base first), prepares a synthetic QA administrator via the existing Filament login, builds and installs the debug app, and starts a loopback-only server on port 8765. It does not overwrite the existing mobile/www files. The admin password is rotated each run and printed locally.

On the PC, sign in at /admin/login using the printed credentials, then open /profile/devices. On the phone, open TRIS DEV, choose the TRIS ID option and enter the printed ID. Approve the request from the PC with the phone's comparison code. Check that the phone opens the home page, preserves its session after restart, and rejects blocked accounts. This exercises the actual web device-link controller; it is not a test of native Telegram Login, FCM, or native API token storage.

Only synthetic accounts and data belong here. Keep the USB-connected device trusted. The APK uses HTTP and the Capacitor development server URL, and must never be shared or shipped. The original capacitor.config.json is restored after the build; generated Android assets still contain the DEV URL until the next cap sync. To return to the standalone placeholder, rerun cap sync android using the original config and rebuild. Ctrl+C stops the local server.

Native Telegram SDK needs a registered Android package/signature and Telegram App Link from BotFather. Firebase needs an independent DEV project and google-services.json. Neither service has been configured here. No production configuration or account changes.

## Independent push smoke build

Place the Firebase DEV project's google-services.json into mobile/android/app. Ensure it belongs to an isolated Firebase project, not production. Then run `powershell.exe -NoProfile -File tools/mobile/Test-Push.ps1` from the root. This builds a separate bundled screen using the official Capacitor push plugin, replacing the installed DEV APK, not the production app. It asks permission, registers with FCM, shows the device token for a console test message, and logs foreground receipt and notification taps. Test with an open app, background, and locked screen. No user binding or Laravel dispatch is implemented yet. The original Capacitor config is restored; package.json/package-lock.json are updated by npm and should be reviewed before commit. The generated assets remain those of the last build until cap sync. Re-run Start-Dev.ps1 to return to the local web integration build.


## Integrated PUSH 0.1

Create an isolated Firebase DEV project, register Android package `eu.trisservice.academy.dev`, and place:
- Android `google-services.json` in `mobile/android/app/`.
- A DEV-only service account JSON with permission to send FCM messages in `storage/app/mobile-dev/firebase-service-account.json` (private; never commit or send it in chat).

The two JSON files must belong to the same DEV Firebase project. Enable FCM HTTP v1 in the project if necessary. The service account needs only the Firebase Cloud Messaging API Admin role; do not use a production account. The existing Start-Dev.ps1 detects the two files and installs the official Google Auth library in tools/mobile/fcm and the Android plugin. If either file is missing the feature stays disabled. No change to main Composer dependencies.

Run Start-Dev.ps1. In the phone's profile open Уведомления and explicitly enable notifications. On the PC sign in with the synthetic QA admin and open /admin/mobile-push. Select the registered device and send a test push. Check the foreground banner, background notification, locked screen, notification tap, and history; refresh the admin page to see callback timestamps. FCM accepted is not confirmed delivery. Test payload contains only a generic test message and its UUID. The page only permits approved active employees, and the sender is restricted to DEV admins. Session logout revokes the current registered token on the Laravel logout path; old registrations expire from sending after 24 hours without registration. Full native session revocation and pending-account approval pushes are not implemented. These routes are unavailable outside mobiledev/testing even if the env flag is set.

The standalone Test-Push.ps1 remains available for testing Firebase independently of Laravel, but is not needed for the integrated build.
