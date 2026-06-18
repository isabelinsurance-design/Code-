#!/usr/bin/env python3
"""
Tempo Server
============
Zero-dependency (stdlib only) collector + API for the Tempo desktop agent.

  - Receives activity samples from one or more agents  (POST /api/ingest)
  - Stores them in SQLite                              (tempo.db)
  - Classifies each sample productive/neutral/distracting via categories.json
  - Serves the aggregated daily report the dashboard reads (GET /api/activity)
  - Serves the dashboard itself                        (GET /)

Run:
    python3 tempo_server.py                # http://localhost:8787
    python3 tempo_server.py 9000           # custom port

Then open http://localhost:8787 and point the agent's SERVER_URL at it.
"""
import json, os, sqlite3, time, base64, sys
from datetime import datetime
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

HERE      = os.path.dirname(os.path.abspath(__file__))
ROOT      = os.path.dirname(HERE)
DASH_DIR  = os.path.join(ROOT, "dashboard")
SHOTS_DIR = os.path.join(HERE, "shots")
DB_PATH   = os.path.join(HERE, "tempo.db")
CAT_PATH  = os.path.join(HERE, "categories.json")
os.makedirs(SHOTS_DIR, exist_ok=True)

DEFAULT_CATEGORIES = {
    "productive":   ["code", "vs code", "github", "gitlab", "figma", "docs.google",
                     "terminal", "iterm", "jira", "notion", "linear", "xcode",
                     "pycharm", "intellij", "stackoverflow", "confluence"],
    "distracting":  ["youtube", "netflix", "twitter", "x.com", "instagram",
                     "facebook", "reddit", "tiktok", "twitch", "9gag", "espn"],
    "neutral":      ["slack", "mail", "gmail", "outlook", "zoom", "teams",
                     "calendar", "whatsapp", "messages", "spotify"],
}

def load_categories():
    try:
        with open(CAT_PATH) as f:
            return json.load(f)
    except Exception:
        return DEFAULT_CATEGORIES

def classify(text, cats):
    t = (text or "").lower()
    for cat in ("distracting", "productive", "neutral"):   # distracting wins ties
        for kw in cats.get(cat, []):
            if kw in t:
                return cat
    return "neutral"

# ── storage ──────────────────────────────────────────────────────────────
def db():
    con = sqlite3.connect(DB_PATH)
    con.execute("""CREATE TABLE IF NOT EXISTS samples(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts INTEGER, user TEXT, app TEXT, title TEXT, url TEXT,
        secs INTEGER, active INTEGER, activity INTEGER, shot TEXT)""")
    return con

def day_bounds(day_offset):
    now = datetime.now()
    start = datetime(now.year, now.month, now.day)
    start_ts = int(start.timestamp()) - day_offset * 86400
    return start_ts, start_ts + 86400

# ── aggregation → the exact shape the dashboard expects ───────────────────
def build_report(day_offset, user):
    cats = load_categories()
    lo, hi = day_bounds(day_offset)
    con = db()
    where = "ts >= ? AND ts < ?"
    args = [lo, hi]
    if user:
        where += " AND user = ?"; args.append(user)
    rows = con.execute(
        f"SELECT ts,app,title,url,secs,active,activity,shot FROM samples WHERE {where} ORDER BY ts",
        args).fetchall()
    con.close()

    apps, sites, blocks, shots = {}, {}, [], []
    active_secs = idle_secs = 0
    act_sum = act_n = 0
    for ts, app, title, url, secs, active, activity, shot in rows:
        secs = secs or 30
        label = f"{app} — {title}" if title else (app or "Unknown")
        cat = classify(label + " " + (url or ""), cats)
        if active:
            active_secs += secs
            apps[app or "Unknown"] = apps.get(app or "Unknown", {"m": 0, "prod": cat})
            apps[app or "Unknown"]["m"] += secs
            if url:
                sites[url] = sites.get(url, {"m": 0, "prod": classify(url, cats)})
                sites[url]["m"] += secs
            act_sum += activity or 0; act_n += 1
            seg = cat
        else:
            idle_secs += secs
            seg = "idle"
        if blocks and blocks[-1]["prod"] == seg:
            blocks[-1]["m"] += secs / 60.0
        else:
            blocks.append({"prod": seg, "m": secs / 60.0})
        if shot:
            shots.append({"t": datetime.fromtimestamp(ts).strftime("%H:%M"),
                          "act": activity or 0, "prod": cat,
                          "app": (app or "")[:22], "file": shot})

    def top(d):
        return [{"name": k, "ico": icon_for(k), "prod": v["prod"], "m": round(v["m"] / 60)}
                for k, v in sorted(d.items(), key=lambda x: -x[1]["m"]) if round(v["m"] / 60) > 0]

    return {
        "live": len(rows) > 0,
        "apps":  top(apps)[:8],
        "sites": top(sites)[:8],
        "blocks": [b for b in blocks if b["m"] > 0.1] or [{"prod": "idle", "m": 1}],
        "shots": shots[-8:],
        "idleMin": round(idle_secs / 60),
        "activityLevel": round(act_sum / act_n) if act_n else 0,
    }

def icon_for(name):
    n = (name or "").lower()
    table = {"chrome": "🌐", "firefox": "🦊", "safari": "🧭", "code": "🟦", "slack": "💬",
             "figma": "🎨", "mail": "✉️", "gmail": "✉️", "youtube": "▶️", "zoom": "🎥",
             "github": "🐙", "terminal": "⌨️", "spotify": "🎵", "notion": "📝",
             "twitter": "🐦", "x.com": "🐦", "docs.google": "📄"}
    for k, v in table.items():
        if k in n:
            return v
    return "🖥️" if "." not in n else "🌐"

# ── HTTP ──────────────────────────────────────────────────────────────────
class Handler(BaseHTTPRequestHandler):
    def _send(self, code, body, ctype="application/json"):
        if isinstance(body, (dict, list)):
            body = json.dumps(body).encode()
        elif isinstance(body, str):
            body = body.encode()
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_OPTIONS(self):
        self._send(204, b"")

    def do_GET(self):
        u = urlparse(self.path)
        q = parse_qs(u.query)
        if u.path == "/api/health":
            return self._send(200, {"ok": True, "ts": int(time.time())})
        if u.path == "/api/activity":
            day = int(q.get("day", ["0"])[0])
            user = q.get("user", [""])[0]
            return self._send(200, build_report(day, user))
        if u.path.startswith("/shots/"):
            return self._serve_file(os.path.join(SHOTS_DIR, os.path.basename(u.path)))
        # static dashboard
        rel = "index.html" if u.path in ("/", "") else u.path.lstrip("/")
        return self._serve_file(os.path.join(DASH_DIR, rel))

    def do_POST(self):
        if urlparse(self.path).path != "/api/ingest":
            return self._send(404, {"error": "not found"})
        length = int(self.headers.get("Content-Length", 0))
        try:
            payload = json.loads(self.rfile.read(length) or b"{}")
        except Exception:
            return self._send(400, {"error": "bad json"})
        samples = payload if isinstance(payload, list) else [payload]
        con = db()
        for s in samples:
            shot = None
            if s.get("shot_b64"):
                shot = f"{s.get('user','agent')}_{int(s.get('ts', time.time()))}.jpg"
                try:
                    with open(os.path.join(SHOTS_DIR, shot), "wb") as f:
                        f.write(base64.b64decode(s["shot_b64"]))
                except Exception:
                    shot = None
            con.execute(
                "INSERT INTO samples(ts,user,app,title,url,secs,active,activity,shot) VALUES(?,?,?,?,?,?,?,?,?)",
                (int(s.get("ts", time.time())), s.get("user", "agent"), s.get("app", ""),
                 s.get("title", ""), s.get("url", ""), int(s.get("secs", 30)),
                 1 if s.get("active", True) else 0, int(s.get("activity", 0)), shot))
        con.commit(); con.close()
        return self._send(200, {"stored": len(samples)})

    def _serve_file(self, path):
        if not os.path.isfile(path):
            return self._send(404, {"error": "not found"})
        ctype = {"html": "text/html", "js": "text/javascript", "css": "text/css",
                 "json": "application/json", "jpg": "image/jpeg", "png": "image/png",
                 "ico": "image/x-icon"}.get(path.rsplit(".", 1)[-1], "application/octet-stream")
        with open(path, "rb") as f:
            self._send(200, f.read(), ctype)

    def log_message(self, *a):  # quieter logs
        if "/api/" in (a[0] if a else ""):
            return

def main():
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8787
    if not os.path.exists(CAT_PATH):
        with open(CAT_PATH, "w") as f:
            json.dump(DEFAULT_CATEGORIES, f, indent=2)
    srv = ThreadingHTTPServer(("0.0.0.0", port), Handler)
    print(f"Tempo Server → http://localhost:{port}")
    print(f"  dashboard : http://localhost:{port}/")
    print(f"  ingest    : POST http://localhost:{port}/api/ingest")
    print(f"  db        : {DB_PATH}")
    try:
        srv.serve_forever()
    except KeyboardInterrupt:
        print("\nbye")

if __name__ == "__main__":
    main()
