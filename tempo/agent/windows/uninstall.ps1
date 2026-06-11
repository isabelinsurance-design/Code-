<#
  Tempo Agent — Windows uninstaller
  Stops the running agent, removes the logon auto-start, and deletes the
  install folder. No admin required.
#>
$ErrorActionPreference = "SilentlyContinue"
$Dest = Join-Path $env:LOCALAPPDATA "Tempo"

Write-Host ""
Write-Host "  Removing Tempo Agent..." -ForegroundColor Cyan

# stop running instances (exe, or pythonw/py running tempo_agent.py)
Get-CimInstance Win32_Process |
    Where-Object {
        $_.Name -eq "TempoAgent.exe" -or
        (($_.Name -in @("pythonw.exe","python.exe","py.exe")) -and $_.CommandLine -like "*tempo_agent*")
    } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force }

# remove auto-start
Remove-ItemProperty -Path "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run" -Name "TempoAgent" -ErrorAction SilentlyContinue

# delete files
if (Test-Path $Dest) { Remove-Item $Dest -Recurse -Force }

Write-Host "  Done. Tempo Agent removed." -ForegroundColor Green
Write-Host ""
