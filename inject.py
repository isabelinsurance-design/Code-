#!/usr/bin/env python3
"""
Keeps every file in tools/ in sync with tools-interceptor.js.

The interceptor gives each standalone tool the shared Anthropic key, the right
request headers and the current model. build.py runs this first, so a new tool
dropped into tools/ gets it automatically and an old copy is replaced.

    python3 inject.py        # sync now (also done by build.py)
"""
import os
import re
import sys

REPO = os.path.dirname(os.path.abspath(__file__))
TOOLS_DIR = os.path.join(REPO, "tools")
SNIPPET_SRC = os.path.join(REPO, "tools-interceptor.js")

BLOCK = re.compile(r"<script>\s*/\* ISABEL UNIFIED.*?</script>\s*", re.S)


def sync_tools(tools_dir=TOOLS_DIR, snippet_src=SNIPPET_SRC):
    with open(snippet_src, encoding="utf-8") as fh:
        js = fh.read().strip()
    block = "<script>\n" + js + "\n</script>\n"
    changed, total = [], 0
    for name in sorted(os.listdir(tools_dir)):
        if not name.endswith(".html"):
            continue
        total += 1
        path = os.path.join(tools_dir, name)
        with open(path, encoding="utf-8") as fh:
            html = fh.read()
        if BLOCK.search(html):
            new = BLOCK.sub(lambda m: block, html, count=1)
        elif "</head>" in html:
            new = html.replace("</head>", block + "</head>", 1)
        else:
            raise SystemExit(f"{name}: no </head> to inject into")
        if new != html:
            with open(path, "w", encoding="utf-8") as fh:
                fh.write(new)
            changed.append(name)
    return total, changed


if __name__ == "__main__":
    total, changed = sync_tools()
    print(f"interceptor: {total} tools checked, {len(changed)} updated")
    for n in changed:
        print("  updated", n)
    sys.exit(0)
