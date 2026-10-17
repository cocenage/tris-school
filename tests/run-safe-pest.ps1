param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $PestArgs
)

$projectRoot = Split-Path -Parent $PSScriptRoot
$configuration = [xml](Get-Content -LiteralPath (Join-Path $projectRoot 'phpunit.xml') -Raw)
$previous = @{}
$result = 1

if (Test-Path -LiteralPath (Join-Path $projectRoot 'bootstrap/cache/config.php')) {
    throw 'Cached Laravel config could override the isolated Pest database settings.'
}

try {
    foreach ($entry in $configuration.phpunit.php.env) {
        $name = [string] $entry.name
        $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        [Environment]::SetEnvironmentVariable($name, [string] $entry.value, 'Process')
    }

    Push-Location $projectRoot
    try {
        & php vendor/bin/pest --no-configuration --compact @PestArgs
        $result = $LASTEXITCODE
    } finally {
        Pop-Location
    }
} finally {
    foreach ($name in $previous.Keys) {
        [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process')
    }
}

exit $result
