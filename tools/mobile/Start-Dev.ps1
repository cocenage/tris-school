# Run in a separate PowerShell process. Only the USB-connected DEV app is affected.
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
Set-Location $root
function Run([scriptblock]$Command) {
    & $Command
    if ($LASTEXITCODE -ne 0) { throw "Command failed (exit $LASTEXITCODE): $Command" }
}
$mobile = Join-Path $root 'mobile'
$configFile = Join-Path $mobile 'capacitor.config.json'
$gradle = Join-Path $mobile 'android\gradlew.bat'
if (!(Test-Path $configFile) -or !(Test-Path $gradle)) {
    throw 'The existing local Capacitor Android project is required.'
}
if (!(Test-Path 'vendor/autoload.php')) { throw 'Run composer install in the auth worktree first.' }
if (Test-Path 'public/hot') { throw 'Stop Vite dev server in this worktree before building.' }

# Remove inherited environment overrides, including prior in-memory test settings.
# This script is intended to run in a child PowerShell process.
Get-ChildItem Env: | Where-Object {
    $_.Name -match '^(TRIS_PUSH_|APP_|DB_|SESSION_|CACHE_|QUEUE_|TELEGRAM_|STAFF_FORMS_|MOBILITY_|TRIS_MARE_|WEATHERAPI_|AWS_|REDIS_|MAIL_|BROADCAST_|FILESYSTEM_)'
} | ForEach-Object { Remove-Item ("Env:" + $_.Name) }

$env:ANDROID_HOME = "$env:LOCALAPPDATA\Android\Sdk"
if (!(Test-Path "$env:ANDROID_HOME\platforms\android-36")) { throw 'Android SDK 36 is missing.' }
$jdk = Get-ChildItem "C:\Program Files\Eclipse Adoptium" -Directory -Filter 'jdk-21*' -ErrorAction SilentlyContinue | Select-Object -First 1
if (!$jdk) { throw 'Temurin JDK 21 not found.' }
$env:JAVA_HOME = $jdk.FullName
$env:Path = "$env:JAVA_HOME\bin;$env:ANDROID_HOME\platform-tools;$env:Path"

$directory = Join-Path $root 'storage\app\mobile-dev'
New-Item -ItemType Directory -Path $directory -Force | Out-Null
foreach ($file in @('primary.sqlite','analytics.sqlite','old.sqlite','queue.sqlite')) {
    $target = Join-Path $directory $file
    if (!(Test-Path $target)) { New-Item -ItemType File -Path $target | Out-Null }
}
$dotenv = Join-Path $root '.env.mobiledev'
$utf8 = New-Object System.Text.UTF8Encoding($false)
if (!(Test-Path $dotenv)) {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $key = [Convert]::ToBase64String($bytes)
    $db = $directory.Replace('\','/')
    $settings = @"
APP_NAME="TRIS DEV"
APP_ENV=mobiledev
APP_KEY=base64:$key
APP_DEBUG=true
APP_URL=http://127.0.0.1:8765
APP_LOCALE=ru
DB_CONNECTION=sqlite
DB_DATABASE="$db/primary.sqlite"
DB_ANALYTICS_DATABASE="$db/analytics.sqlite"
DB_OLD_DATABASE="$db/old.sqlite"
DB_QUEUE_DATABASE="$db/queue.sqlite"
SESSION_DRIVER=database
SESSION_COOKIE=tris_mobile_dev_session
SESSION_LIFETIME=43200
SESSION_SECURE_COOKIE=false
CACHE_STORE=array
QUEUE_CONNECTION=sync
MAIL_MAILER=array
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
TELEGRAM_BOT_TOKEN=
TELEGRAM_BOT_USERNAME=
TELEGRAM_ADMIN_CHAT_ID=
TELEGRAM_SCHEDULED_BOT_TOKEN=
TELEGRAM_ANALYTICS_BOT_TOKEN=
TELEGRAM_OPERATIONAL_OBSERVER_ENABLED=false
TELEGRAM_EVENING_INTELLIGENCE_DELIVERY_ENABLED=false
MOBILITY_STRIKE_NOTIFICATIONS_ENABLED=false
"@
    [IO.File]::WriteAllText($dotenv, $settings, $utf8)
}
$env:APP_ENV = 'mobiledev'
$env:APP_CONFIG_CACHE = Join-Path $directory 'config.php'
$env:APP_ROUTES_CACHE = Join-Path $directory 'routes.php'
if ((Test-Path $env:APP_CONFIG_CACHE) -or (Test-Path $env:APP_ROUTES_CACHE)) {
    throw 'Unexpected cached mobile config or routes. Inspect the mobile-dev directory.'
}

$firebase = Join-Path $mobile 'android\app\google-services.json'
$credentials = Join-Path $directory 'firebase-service-account.json'
$env:TRIS_PUSH_ENABLED = 'false'
if ((Test-Path $firebase) -and (Test-Path $credentials)) {
    $google = Get-Content $firebase -Raw | ConvertFrom-Json
    $service = Get-Content $credentials -Raw | ConvertFrom-Json
    if (!($google.client | Where-Object { $_.client_info.android_client_info.package_name -eq 'eu.trisservice.academy.dev' })) {
        throw 'Firebase Android configuration does not match the DEV package.'
    }
    if (!$google.project_info.project_id -or $google.project_info.project_id -ne $service.project_id -or $service.type -ne 'service_account') {
        throw 'Firebase Android configuration and service account must belong to the same DEV project.'
    }
    $env:TRIS_PUSH_PROJECT_ID = $google.project_info.project_id
    $env:TRIS_PUSH_ENABLED = 'true'
    Run { composer install --working-dir=tools/mobile/fcm --no-interaction }
    Set-Location $mobile
    Run { npm.cmd install @capacitor/push-notifications@8 }
    Set-Location $root
    Write-Host 'PUSH 0.1 enabled for this isolated DEV project.'
} else {
    Write-Host 'Push disabled: both DEV Firebase files are required.'
}
Run { npm.cmd ci }
Run { npm.cmd run build }
Run { php tools/mobile/bootstrap-dev.php }

# Temporarily configure the existing debug APK to open the local Laravel server.
# Do not commit this cleartext server URL or use it in release builds.
$original = [IO.File]::ReadAllBytes($configFile)
try {
    $config = Get-Content $configFile -Raw | ConvertFrom-Json
    if ($config.appId -ne 'eu.trisservice.academy.dev') {
        throw 'Refusing to modify a non-DEV app ID.'
    }
    $config | Add-Member -NotePropertyName server -NotePropertyValue ([pscustomobject]@{
        url = 'http://127.0.0.1:8765'
        cleartext = $true
    }) -Force
    [IO.File]::WriteAllText($configFile, ($config | ConvertTo-Json -Depth 30), $utf8)
    Set-Location $mobile
    Run { npx.cmd cap sync android }
    Set-Location (Join-Path $mobile 'android')
    Run { .\gradlew.bat assembleDebug }
    Run { adb reverse tcp:8765 tcp:8765 }
    Run { adb install -r .\app\build\outputs\apk\debug\app-debug.apk }
} finally {
    [IO.File]::WriteAllBytes($configFile, $original)
    Set-Location $root
}

Write-Host 'Open TRIS DEV on the connected phone after Laravel starts.'
Write-Host 'In the PC browser, sign into the QA admin at http://127.0.0.1:8765/admin/login.'
Write-Host 'The phone can connect with the printed TRIS ID; approve it at /profile/devices.'
Write-Host 'USB-only HTTP debug build. Never distribute this APK. Ctrl+C stops Laravel.'
try {
    Run { php artisan serve --host=127.0.0.1 --port=8765 --no-reload --env=mobiledev }
} finally {
    adb reverse --remove tcp:8765
}
