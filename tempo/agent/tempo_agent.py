#!/usr/bin/env python3
"""
Tempo Agent  —  the real desktop tracker
========================================
Runs in the background on the employee's machine and reports, every few
seconds: the active application, window title, browser URL (best-effort),
how long the user has been idle, and (optionally) a screenshot.

Cross-platform with NO required pip installs:
    Windows  -> ctypes (user32/kernel32)
    macOS    -> osascript + ioreg
    Linux    -> xdotool / xprintidle  (install via your package manager)

Screenshots are OPTIONAL and only happen if `mss` or `Pillow` is installed
(`pip install mss`).  Without them everything else still works.

Configure below, then:
    python3 tempo_agent.py

⚠️  Monitoring people's computers has legal/consent obligations that vary by
    region. Notify users and get consent before deploying. See README.
"""
import sys, time, json, platform, subprocess, urllib.request, base64, io, os

# ───────────────────────── CONFIG ─────────────────────────
SERVER_URL          = os.environ.get("TEMPO_SERVER", "http://localhost:8787")
USER                = os.environ.get("TEMPO_USER", platform.node() or "agent")
SAMPLE_INTERVAL     = 30      # seconds between activity samples
SCREENSHOT_INTERVAL = 600     # seconds between screenshots (0 = disabled)
IDLE_THRESHOLD      = 60      # seconds with no input == "idle/away"
ENABLE_SCREENSHOTS  = True    # also needs mss or Pillow installed
SCREENSHOT_QUALITY  = 35      # jpeg quality 1-95 (lower = smaller upload)
# ───────────────────────────────────────────────────────────

OS = platform.system()  # 'Windows' | 'Darwin' | 'Linux'


# ═══════════════ ACTIVE WINDOW / APP / URL ═══════════════
def active_window():
    """Return {'app','title','url'} best-effort for the current platform."""
    try:
        if OS == "Windows":
            return _win_active()
        if OS == "Darwin":
            return _mac_active()
        return _linux_active()
    except Exception as e:
        return {"app": "Unknown", "title": "", "url": "", "_err": str(e)}


def _win_active():
    import ctypes
    from ctypes import wintypes
    u32, k32, psapi = ctypes.windll.user32, ctypes.windll.kernel32, ctypes.windll.psapi
    hwnd = u32.GetForegroundWindow()
    n = u32.GetWindowTextLengthW(hwnd)
    buf = ctypes.create_unicode_buffer(n + 1)
    u32.GetWindowTextW(hwnd, buf, n + 1)
    title = buf.value
    pid = wintypes.DWORD()
    u32.GetWindowThreadProcessId(hwnd, ctypes.byref(pid))
    app = "Unknown"
    h = k32.OpenProcess(0x0410, False, pid)  # QUERY_INFO | VM_READ
    if h:
        nb = ctypes.create_unicode_buffer(260)
        if psapi.GetModuleBaseNameW(h, None, nb, 260):
            app = nb.value.replace(".exe", "")
        k32.CloseHandle(h)
    return {"app": app, "title": title, "url": _url_from_title(app, title)}


def _mac_active():
    app = _osa('tell application "System Events" to get name of first '
               'application process whose frontmost is true')
    title, url = "", ""
    low = app.lower()
    if "chrome" in low or "brave" in low or "edge" in low:
        url = _osa(f'tell application "{app}" to get URL of active tab of front window')
        title = _osa(f'tell application "{app}" to get title of active tab of front window')
    elif "safari" in low:
        url = _osa('tell application "Safari" to get URL of front document')
        title = _osa('tell application "Safari" to get name of front document')
    else:
        title = _osa(f'tell application "System Events" to tell process "{app}" '
                     'to get title of front window') or app
    return {"app": app, "title": title, "url": _host(url)}


def _linux_active():
    win = _sh(["xdotool", "getactivewindow"]).strip()
    title = _sh(["xdotool", "getwindowname", win]).strip() if win else ""
    cls = _sh(["xdotool", "getwindowclassname", win]).strip() if win else ""
    app = cls or (title.split(" - ")[-1] if " - " in title else title) or "Unknown"
    return {"app": app, "title": title, "url": _url_from_title(app, title)}


# ═══════════════ IDLE TIME ═══════════════
def idle_seconds():
    try:
        if OS == "Windows":
            import ctypes
            class LII(ctypes.Structure):
                _fields_ = [("cbSize", ctypes.c_uint), ("dwTime", ctypes.c_uint)]
            lii = LII(); lii.cbSize = ctypes.sizeof(LII)
            ctypes.windll.user32.GetLastInputInfo(ctypes.byref(lii))
            return (ctypes.windll.kernel32.GetTickCount() - lii.dwTime) / 1000.0
        if OS == "Darwin":
            out = _sh(["ioreg", "-c", "IOHIDSystem"])
            for line in out.splitlines():
                if "HIDIdleTime" in line:
                    return int(line.split("=")[-1].strip()) / 1_000_000_000.0
        else:  # Linux
            ms = _sh(["xprintidle"]).strip()
            if ms.isdigit():
                return int(ms) / 1000.0
    except Exception:
        pass
    return 0.0


# ═══════════════ SCREENSHOT (optional) ═══════════════
def screenshot_b64():
    if not ENABLE_SCREENSHOTS:
        return None
    try:
        import mss, mss.tools
        with mss.mss() as s:
            raw = mss.tools.to_png(s.grab(s.monitors[0]).rgb, s.grab(s.monitors[0]).size)
        return _to_jpeg_b64(raw)
    except Exception:
        pass
    try:
        from PIL import ImageGrab, Image  # noqa
        img = ImageGrab.grab()
        buf = io.BytesIO(); img.convert("RGB").save(buf, "JPEG", quality=SCREENSHOT_QUALITY)
        return base64.b64encode(buf.getvalue()).decode()
    except Exception:
        return None


def _to_jpeg_b64(png_bytes):
    try:
        from PIL import Image
        img = Image.open(io.BytesIO(png_bytes)).convert("RGB")
        buf = io.BytesIO(); img.save(buf, "JPEG", quality=SCREENSHOT_QUALITY)
        return base64.b64encode(buf.getvalue()).decode()
    except Exception:
        return base64.b64encode(png_bytes).decode()  # fall back to png bytes


# ═══════════════ helpers ═══════════════
def _osa(script):
    try:
        return subprocess.run(["osascript", "-e", script], capture_output=True,
                              text=True, timeout=4).stdout.strip()
    except Exception:
        return ""

def _sh(cmd):
    try:
        return subprocess.run(cmd, capture_output=True, text=True, timeout=4).stdout
    except Exception:
        return ""

def _host(url):
    if not url:
        return ""
    u = url.split("//")[-1].split("/")[0]
    return u[4:] if u.startswith("www.") else u

def _url_from_title(app, title):
    # browsers on Win/Linux often put the host in the window title; best-effort
    if any(b in (app or "").lower() for b in ("chrome", "firefox", "edge", "brave", "opera")):
        for tok in (title or "").replace(" — ", " - ").split(" - "):
            if "." in tok and " " not in tok.strip():
                return _host(tok.strip())
    return ""


def post(samples):
    try:
        data = json.dumps(samples).encode()
        req = urllib.request.Request(SERVER_URL + "/api/ingest", data=data,
                                     headers={"Content-Type": "application/json"})
        urllib.request.urlopen(req, timeout=6).read()
        return True
    except Exception as e:
        print(f"  ! upload failed: {e}")
        return False


def main():
    print(f"Tempo Agent · {OS} · user={USER}")
    print(f"  server={SERVER_URL}  sample={SAMPLE_INTERVAL}s  "
          f"screenshots={'on' if (ENABLE_SCREENSHOTS and SCREENSHOT_INTERVAL) else 'off'}")
    print("  Ctrl-C to stop.\n")
    last_shot = 0
    while True:
        loop_start = time.time()
        win = active_window()
        idle = idle_seconds()
        active = idle < IDLE_THRESHOLD
        activity = max(0, min(100, int(100 - idle * (100 / IDLE_THRESHOLD)))) if active else 0
        sample = {
            "ts": int(time.time()), "user": USER,
            "app": win.get("app", ""), "title": win.get("title", ""),
            "url": win.get("url", ""), "secs": SAMPLE_INTERVAL,
            "active": active, "activity": activity,
        }
        if (ENABLE_SCREENSHOTS and SCREENSHOT_INTERVAL and active
                and time.time() - last_shot >= SCREENSHOT_INTERVAL):
            shot = screenshot_b64()
            if shot:
                sample["shot_b64"] = shot
                last_shot = time.time()
        ok = post(sample)
        flag = "●" if active else "○"
        print(f"  {flag} {sample['app'][:18]:<18} {('· '+sample['url']) if sample['url'] else '':<24} "
              f"idle={int(idle)}s {'↑' if ok else '×'}")
        time.sleep(max(1, SAMPLE_INTERVAL - (time.time() - loop_start)))


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        print("\nstopped.")
        sys.exit(0)
