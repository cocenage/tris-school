# TRIS page links — Android App Links

The page-copy action copies the current HTTPS page URL, without query parameters or fragments. In DEV it copies the current loopback URL; that URL is not a shareable production link. Authorization remains on the destination route. No Telegram callback, device pairing token, or signed URL should be copied.

## Production setup (not yet verified)

1. Use the actual production HTTPS host used by the Capacitor WebView. Obtain the release application ID and SHA-256 fingerprint of the certificate signing the installed APK (Play App Signing certificate if distributed by Play). Do not use the DEV package or debug certificate in production.
2. Set TRIS_ANDROID_APP_LINK_PACKAGE and TRIS_ANDROID_APP_LINK_SHA256 on Laravel. For multiple fingerprints use comma-separated values. Run php artisan config:cache. Verify the exact public URL https://<host>/.well-known/assetlinks.json returns 200 JSON without redirects, cookies, authentication or a proxy/static-file override.
3. In the real Android project install the matching Capacitor App plugin (for Capacitor 8: npm install @capacitor/app@8), then run npx cap sync android. The actual generated native project is local and is not available on this branch.
4. Add an independent intent filter to MainActivity in mobile/android/app/src/main/AndroidManifest.xml, using the real host:

```xml
<intent-filter android:autoVerify="true">
    <action android:name="android.intent.action.VIEW" />
    <category android:name="android.intent.category.DEFAULT" />
    <category android:name="android.intent.category.BROWSABLE" />
    <data android:scheme="https" android:host="PRODUCTION_HOST" />
</intent-filter>
```

Keep the Telegram login callback filter separate and preserve the existing singleTask / onNewIntent handling. Rebuild and install the signed APK. The runtime in public/js/tris-page-links.js listens for App.appUrlOpen and App.getLaunchUrl, validates same-origin HTTPS URLs, and navigates the WebView. The WebView must load the same production HTTPS origin, not a localhost bundle; a later bundled API client will need a dedicated route resolver.

5. Verify on an Android 12+ device:
   - adb shell pm verify-app-links --re-verify APPLICATION_ID
   - adb shell pm get-app-links APPLICATION_ID
   - adb shell am start -a android.intent.action.VIEW -c android.intent.category.BROWSABLE -d "https://PRODUCTION_HOST/home/instructions"

Test from Telegram and other apps, with TRIS running and stopped, signed in and signed out, authorized and unauthorized roles, and a non-installed device (browser fallback). Check an actual apartment and instruction link and a Filament record link. Verify post-login return to the requested page; current login flows may need explicit intended-route handling. User app-link preferences and in-app browsers can override OS routing.

The server association is deliberately disabled (404) when production values are not configured. No production host, package or fingerprint is guessed. The local USB build cannot prove real external HTTPS routing. Do not mark this feature release-ready before verifying the signed APK and domain.
