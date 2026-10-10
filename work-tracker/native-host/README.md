# Desktop-app helper (optional)

Chrome can see what's happening in Chrome, but not in other programs. Without this
helper, time in any other app shows on the dashboard as **"another app (outside
Chrome)"**. With it, that time is labelled with the app's name — **Nextiva**,
**Microsoft Excel**, and so on — in the live cards, the activity log and the
"CRM areas & apps" list.

Everything else works without it. Install it only if you want desktop apps named.

## What it sends

**Only the name of the app in front, while the person is outside Chrome.** Nothing
else: no window titles, no document or file names, no page contents, no keystrokes.
The extension ignores the name whenever Chrome itself is in front.

## Install (Windows, per computer — do this after the Chrome extension)

1. Copy this `native-host` folder onto the computer (anywhere, e.g. the Desktop).
2. Open `chrome://extensions`, find **NetConnect Work Tracker**, and copy its **ID**
   (32 letters, like `abcdef…`).
3. Right-click **install.ps1** → **Run with PowerShell**. Paste the ID when asked.
   (If Windows blocks it: open PowerShell and run
   `powershell -ExecutionPolicy Bypass -File install.ps1`.)
4. Fully quit Chrome and open it again.

Works for Chrome and Edge. No admin rights needed — it installs for the current
user only. To force it onto every computer, deploy the folder and the registry key
`HKCU\Software\Google\Chrome\NativeMessagingHosts\com.netconnect.apptracker`
(pointing at `com.netconnect.apptracker.json`) with your management tool.

## Remove

Right-click **uninstall.ps1** → **Run with PowerShell**.

## Files

| File | What it is |
|---|---|
| `apptracker.ps1` | The helper. Reports the foreground app's name every ~2.5 s when it changes. |
| `apptracker.bat` | What Chrome launches; it runs the PowerShell helper hidden. |
| `com.netconnect.apptracker.json` | Native-messaging manifest. `install.ps1` fills in the extension ID and the full path. |
| `install.ps1` / `uninstall.ps1` | Register / unregister the helper for the current user. |

Mac/Linux aren't covered here; ask and the same helper can be written for them.
