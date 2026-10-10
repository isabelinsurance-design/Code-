# NetConnect Work Tracker — desktop-app helper (Windows)
#
# Chrome extensions can't see other programs. This tiny helper tells the
# extension only the NAME of the app in front (e.g. "Microsoft Excel",
# "Nextiva") while the person is outside Chrome, so the dashboard can show it.
#
# It reads nothing else: no window titles, no document names, no file paths,
# no contents, no keystrokes. The extension ignores the name whenever Chrome
# itself is in front. Installed by install.ps1; started by Chrome, not by hand.

$ErrorActionPreference = 'Stop'

Add-Type @"
using System;
using System.Runtime.InteropServices;
using System.Diagnostics;
public static class Fg {
  [DllImport("user32.dll")] static extern IntPtr GetForegroundWindow();
  [DllImport("user32.dll")] static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint pid);
  public static string App() {
    IntPtr h = GetForegroundWindow();
    if (h == IntPtr.Zero) return null;
    uint pid; GetWindowThreadProcessId(h, out pid);
    try {
      Process p = Process.GetProcessById((int)pid);
      string name = null;
      try { name = p.MainModule.FileVersionInfo.FileDescription; } catch { }
      if (string.IsNullOrWhiteSpace(name)) name = p.ProcessName;   // fall back to the exe name
      return name;
    } catch { return null; }
  }
}
"@

# ----- Chrome native-messaging framing: 4-byte little-endian length + UTF-8 JSON -----
$stdout = [Console]::OpenStandardOutput()
function Send-Message($obj) {
  $json  = $obj | ConvertTo-Json -Compress
  $bytes = [Text.Encoding]::UTF8.GetBytes($json)
  try {
    $stdout.Write([BitConverter]::GetBytes([int]$bytes.Length), 0, 4)
    $stdout.Write($bytes, 0, $bytes.Length)
    $stdout.Flush()
  } catch { exit 0 }                                 # pipe closed: Chrome is gone
}

# When Chrome closes the connection, stdin reaches end-of-stream: exit then.
# (Polled with a non-blocking read; a background thread can't run PowerShell code.)
$stdin  = [Console]::OpenStandardInput()
$inBuf  = New-Object byte[] 64
$pending = $stdin.BeginRead($inBuf, 0, $inBuf.Length, $null, $null)
function Test-ChromeGone {
  if (-not $script:pending.IsCompleted) { return $false }
  $n = $stdin.EndRead($script:pending)
  if ($n -le 0) { return $true }                     # end of stream: Chrome is gone
  $script:pending = $stdin.BeginRead($inBuf, 0, $inBuf.Length, $null, $null)   # ignore what was sent, keep watching
  return $false
}

$last = [object]'__start__'
while ($true) {
  if (Test-ChromeGone) { exit 0 }
  $app = [Fg]::App()
  if ($app -ne $last) {
    $last = $app
    Send-Message @{ app = $app }
  }
  Start-Sleep -Milliseconds 2500
}
