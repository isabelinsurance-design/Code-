# Removes the NetConnect Work Tracker desktop-app helper (per user).
$ErrorActionPreference = 'SilentlyContinue'
foreach ($browser in @('Google\Chrome', 'Microsoft\Edge')) {
  Remove-Item -Path "HKCU:\Software\$browser\NativeMessagingHosts\com.netconnect.apptracker" -Force
}
Write-Host 'Removed. Desktop apps will show as "another app (outside Chrome)" again.' -ForegroundColor Green
Read-Host 'Press Enter to close'
