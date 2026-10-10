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
  $stdout.Write([BitConverter]::GetBytes([int]$bytes.Length), 0, 4)
  $stdout.Write($bytes, 0, $bytes.Length)
  $stdout.Flush()
}

# When Chrome closes the connection, stdin reaches end-of-stream: exit then.
$stdin = [Console]::OpenStandardInput()
$watcher = [System.Threading.Thread]::new({
  try { while ($stdin.ReadByte() -ge 0) { } } catch { }
  [Environment]::Exit(0)
})
$watcher.IsBackground = $true
$watcher.Start()

$last = [object]'__start__'
while ($true) {
  $app = [Fg]::App()
  if ($app -ne $last) {
    $last = $app
    Send-Message @{ app = $app }
  }
  Start-Sleep -Milliseconds 2500
}
