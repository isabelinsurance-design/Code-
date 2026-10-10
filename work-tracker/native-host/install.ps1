# NetConnect Work Tracker — install the desktop-app helper (Windows, per user).
# Run once on each computer AFTER the Chrome extension is installed:
#   1. Open chrome://extensions, find "NetConnect Work Tracker", copy its ID.
#   2. Right-click this file → Run with PowerShell (or: powershell -ExecutionPolicy Bypass -File install.ps1)
#   3. Paste the extension ID when asked.
# To remove it later, run uninstall.ps1.

$ErrorActionPreference = 'Stop'
$dir = Split-Path -Parent $MyInvocation.MyCommand.Path
$host.UI.RawUI.WindowTitle = 'NetConnect Work Tracker helper'

$extId = Read-Host 'Paste the extension ID from chrome://extensions'
$extId = $extId.Trim()
if ($extId -notmatch '^[a-p]{32}$') { Write-Host 'That does not look like a Chrome extension ID (32 letters a-p).' -ForegroundColor Red; Read-Host 'Press Enter to close'; exit 1 }

$manifest = [ordered]@{
  name           = 'com.netconnect.apptracker'
  description    = 'NetConnect Work Tracker desktop-app helper (names the app in front, nothing else)'
  path           = (Join-Path $dir 'apptracker.bat')
  type           = 'stdio'
  allowed_origins = @("chrome-extension://$extId/")
}
$manifestPath = Join-Path $dir 'com.netconnect.apptracker.json'
$manifest | ConvertTo-Json | Set-Content -Path $manifestPath -Encoding UTF8

# Register for Chrome and Edge (per-user; no admin rights needed).
foreach ($browser in @('Google\Chrome', 'Microsoft\Edge')) {
  $key = "HKCU:\Software\$browser\NativeMessagingHosts\com.netconnect.apptracker"
  New-Item -Path $key -Force | Out-Null
  Set-ItemProperty -Path $key -Name '(default)' -Value $manifestPath
}

Write-Host "Installed. Fully quit Chrome and open it again." -ForegroundColor Green
Write-Host "On the dashboard, desktop apps will show by name instead of 'another app'."
Read-Host 'Press Enter to close'
