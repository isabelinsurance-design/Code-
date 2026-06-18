<#
  Tempo Agent — Windows installer
  Installs the agent to %LOCALAPPDATA%\Tempo, writes its config, registers it
  to auto-start at logon (per-user, NO admin required), and starts it now.

  Run via install.bat (double-click), or:
      powershell -ExecutionPolicy Bypass -File install.ps1
#>
$ErrorActionPreference = "Stop"
$src  = Split-Path -Parent $PSCommandPath
$Dest = Join-Path $env:LOCALAPPDATA "Tempo"
New-Item -ItemType Directory -Force -Path $Dest | Out-Null

Write-Host ""
Write-Host "  Tempo Agent — installer" -ForegroundColor Cyan
Write-Host "  destination: $Dest"
Write-Host ""

# ── copy payload (prefer the standalone .exe if it was built) ──────────────
$exeSrc  = Join-Path $src "TempoAgent.exe"
$haveExe = Test-Path $exeSrc
if ($haveExe) {
    Copy-Item $exeSrc $Dest -Force
    Write-Host "  using bundled TempoAgent.exe (no Python needed)"
} else {
    Copy-Item (Join-Path $src "..\tempo_agent.py") $Dest -Force
    Write-Host "  using tempo_agent.py (requires Python 3 on this PC)"
}

# ── prompt for settings ────────────────────────────────────────────────────
$server = Read-Host "  Tempo server URL [http://localhost:8787]"
if ([string]::IsNullOrWhiteSpace($server)) { $server = "http://localhost:8787" }
$user = Read-Host "  Label for this computer/user [$env:USERNAME]"
if ([string]::IsNullOrWhiteSpace($user)) { $user = $env:USERNAME }
$shotsAns = Read-Host "  Capture screenshots? (Y/n)"
$shots = -not ($shotsAns -match '^(n|no)$')

# ── write config ────────────────────────────────────────────────────────────
$cfg = [ordered]@{
    server              = $server
    user                = $user
    enable_screenshots  = $shots
    screenshot_interval = 600
    sample_interval     = 30
    idle_threshold      = 60
    logfile             = (Join-Path $Dest "tempo_agent.log")
}
$cfg | ConvertTo-Json | Set-Content -Path (Join-Path $Dest "tempo_config.json") -Encoding UTF8

# ── figure out how to launch it ─────────────────────────────────────────────
if ($haveExe) {
    $launchFile = Join-Path $Dest "TempoAgent.exe"; $launchArgs = @()
} else {
    $script = Join-Path $Dest "tempo_agent.py"
    $pyw = (Get-Command pythonw.exe -ErrorAction SilentlyContinue).Source
    if ($pyw) {
        $launchFile = $pyw; $launchArgs = @($script)
    } elseif (Get-Command py.exe -ErrorAction SilentlyContinue) {
        $launchFile = "pyw.exe"; $launchArgs = @("-3", $script)
        if (-not (Get-Command pyw.exe -ErrorAction SilentlyContinue)) { $launchFile = "py.exe" }
    } else {
        Write-Host ""
        Write-Host "  ERROR: Python 3 not found and no TempoAgent.exe present." -ForegroundColor Red
        Write-Host "  Install Python from https://python.org (check 'Add to PATH')," -ForegroundColor Red
        Write-Host "  or run build_exe.bat once on a build machine to create TempoAgent.exe." -ForegroundColor Red
        exit 1
    }
}

# value for the Run key (single command string)
if ($launchArgs.Count) {
    $runVal = '"' + $launchFile + '" ' + (($launchArgs | ForEach-Object { '"' + $_ + '"' }) -join ' ')
} else {
    $runVal = '"' + $launchFile + '"'
}

# ── auto-start at logon (per-user Run key, no admin) ────────────────────────
$runKey = "HKCU:\Software\Microsoft\Windows\CurrentVersion\Run"
New-ItemProperty -Path $runKey -Name "TempoAgent" -Value $runVal -PropertyType String -Force | Out-Null

# ── start it now (hidden) ───────────────────────────────────────────────────
if ($launchArgs.Count) {
    Start-Process -WindowStyle Hidden -FilePath $launchFile -ArgumentList $launchArgs
} else {
    Start-Process -WindowStyle Hidden -FilePath $launchFile
}

Write-Host ""
Write-Host "  Installed and started." -ForegroundColor Green
Write-Host "  - auto-starts at logon for $env:USERNAME"
Write-Host "  - log: $($cfg.logfile)"
Write-Host "  - server: $server   user: $user   screenshots: $shots"
Write-Host "  To remove: run uninstall.bat"
Write-Host ""
