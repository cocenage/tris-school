$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$mobile = Join-Path $root 'mobile'
$configFile = Join-Path $mobile 'capacitor.config.json'
$firebase = Join-Path $mobile 'android\app\google-services.json'
if (!(Test-Path $configFile)) { throw 'Existing Capacitor project required.' }
if (!(Test-Path $firebase)) { throw 'Missing DEV google-services.json. Put it in mobile/android/app.' }
$config = Get-Content $configFile -Raw | ConvertFrom-Json
if ($config.appId -ne 'eu.trisservice.academy.dev') { throw 'DEV app ID required.' }
$google = Get-Content $firebase -Raw | ConvertFrom-Json
if (!($google.client | Where-Object { $_.client_info.android_client_info.package_name -eq $config.appId })) {
    throw 'Firebase config does not match the DEV app ID.'
}
function Run([scriptblock]$Command) {
    & $Command
    if ($LASTEXITCODE -ne 0) { throw "Command failed (exit $LASTEXITCODE): $Command" }
}
$env:ANDROID_HOME = "$env:LOCALAPPDATA\Android\Sdk"
$jdk = Get-ChildItem 'C:\Program Files\Eclipse Adoptium' -Directory -Filter 'jdk-21*' | Select-Object -First 1
if (!$jdk) { throw 'JDK 21 required.' }
$env:JAVA_HOME = $jdk.FullName
$env:Path = "$env:JAVA_HOME\bin;$env:ANDROID_HOME\platform-tools;$env:Path"
Set-Location $root
if (!(Test-Path 'node_modules/vite/bin/vite.js')) { Run { npm.cmd ci } }
Set-Location $mobile
Run { npm.cmd install @capacitor/push-notifications@8 }
$src = Join-Path $mobile '.push-smoke-src'
New-Item -ItemType Directory -Force -Path $src | Out-Null
Copy-Item (Join-Path $PSScriptRoot 'push-smoke\*') -Destination $src -Force
Run { node (Join-Path $root 'node_modules\vite\bin\vite.js') build $src --config (Join-Path $PSScriptRoot 'push-vite.config.mjs') --outDir ../.push-smoke-dist --emptyOutDir }
$original = [IO.File]::ReadAllBytes($configFile)
$utf8 = New-Object System.Text.UTF8Encoding($false)
try {
    $config.PSObject.Properties.Remove('server')
    $config.webDir = '.push-smoke-dist'
    [IO.File]::WriteAllText($configFile, ($config | ConvertTo-Json -Depth 30), $utf8)
    Run { npx.cmd cap sync android }
    Set-Location (Join-Path $mobile 'android')
    Run { .\gradlew.bat assembleDebug }
    Run { adb install -r .\app\build\outputs\apk\debug\app-debug.apk }
    Run { adb shell am start -n eu.trisservice.academy.dev/.MainActivity }
} finally {
    [IO.File]::WriteAllBytes($configFile, $original)
    Set-Location $root
}
Write-Host 'Use the registration button and Firebase Console test message on the phone. Never share the token.'
