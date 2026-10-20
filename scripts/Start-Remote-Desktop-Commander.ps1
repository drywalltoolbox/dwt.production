$ErrorActionPreference = 'Stop'

Write-Host 'Starting Remote Desktop Commander...' -ForegroundColor Cyan

$nodeCommand = Get-Command node -ErrorAction SilentlyContinue
if (-not $nodeCommand) {
    Write-Host 'Node.js is not installed or is not on PATH. Install Node.js 22.12.0 or newer, then reopen PowerShell.' -ForegroundColor Red
    Read-Host 'Press Enter to close'
    exit 1
}

$nodeVersionText = (& node --version 2>$null | Select-Object -First 1)
if ($nodeVersionText -notmatch '^v?(\d+)\.(\d+)\.(\d+)') {
    Write-Host "Could not determine Node.js version (reported: $nodeVersionText)." -ForegroundColor Red
    Read-Host 'Press Enter to close'
    exit 1
}

$nodeVersion = [version]::new([int]$Matches[1], [int]$Matches[2], [int]$Matches[3])
if ($nodeVersion -lt [version]'22.12.0') {
    Write-Host "Node.js $nodeVersionText is too old. Remote Desktop Commander needs Node.js 22.12.0 or newer." -ForegroundColor Red
    Write-Host 'Install/update Node.js, close this window, and run this script again.' -ForegroundColor Yellow
    Read-Host 'Press Enter to close'
    exit 1
}

Write-Host "Node.js $nodeVersionText found. Starting the remote connection." -ForegroundColor Green
Write-Host 'Keep this window open while you want the desktop connected.' -ForegroundColor Yellow
Write-Host ''

try {
    & npx --yes @wonderwhy-er/desktop-commander@latest remote
    $exitCode = $LASTEXITCODE
} catch {
    Write-Host "Could not start the connection: $_" -ForegroundColor Red
    $exitCode = 1
}

if ($exitCode -ne 0) {
    Write-Host "Remote Desktop Commander exited with code $exitCode." -ForegroundColor Red
}
Read-Host 'Connection ended. Press Enter to close'
exit $exitCode
