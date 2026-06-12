#!/usr/bin/env python3
"""
Build script for the Sistema Maestro de Isabel Fuentes.

Regenerates `isabel-sistema-completo-UNICO.html` from `index.html` and the
`tools/` directory, embedding all 18 tool HTML files as blob URLs so the
result is a single self-contained file deployable to LUNA on Bluehost.

This script is the ONLY supported way to produce the UNICO build — do not
edit the UNICO file by hand. After any change to `index.html` or `tools/`,
run:

    python3 build.py

It writes `isabel-sistema-completo-UNICO.html` next to this script and
prints the resulting size.
"""
import json
import os
import sys

REPO = os.path.dirname(os.path.abspath(__file__))
SOURCE = os.path.join(REPO, "index.html")
TOOLS_DIR = os.path.join(REPO, "tools")
OUTPUT = os.path.join(REPO, "isabel-sistema-completo-UNICO.html")

# These two markers bracket the existing browser-side openTool() function
# in the source. The block between them is replaced with a blob-URL version
# of openTool that pulls from the embedded TOOL_DATA constant instead of
# loading files from disk.
OPEN_TOOL_START = "// ─── FULL TOOLS (iframe) ───"
OPEN_TOOL_END = "  closeSidebar();\n}"

BLOB_OPEN_TOOL = """
// ─── FULL TOOLS (blob-url single-file mode) ───
const TOOL_BASE = 'tools/';
let currentToolFile = '';
const blobCache = {};
function openTool(file, btn, title){
  document.querySelectorAll('.module').forEach(m=>m.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n=>n.classList.remove('active'));
  if(btn) btn.classList.add('active');
  const mod = document.getElementById('mod-tool');
  mod.classList.add('active');
  document.getElementById('toolTitle').textContent = title || 'Herramienta';
  const link = document.getElementById('toolNewTab');
  currentToolFile = file;
  const frame = document.getElementById('toolFrame');
  if(frame.getAttribute('data-file') !== file){
    frame.setAttribute('data-file', file);
    if(TOOL_DATA[file]){
      if(!blobCache[file]){ const blob=new Blob([TOOL_DATA[file]],{type:'text/html'}); blobCache[file]=URL.createObjectURL(blob); }
      link.href=blobCache[file]; frame.src=blobCache[file];
    } else { frame.src=TOOL_BASE+file; link.href=TOOL_BASE+file; }
  }
  try { logEvent('tool', file); } catch(_){}
  closeSidebar();
}"""


def safe_js_string(s: str) -> str:
    """JSON-encode and escape </ and <!-- so the HTML parser can't break out
    of the <script> block that hosts TOOL_DATA."""
    return json.dumps(s).replace("</", "<\\/").replace("<!--", "<\\!--")


def main() -> int:
    with open(SOURCE, encoding="utf-8") as fh:
        shell = fh.read()

    tools = {}
    for name in sorted(os.listdir(TOOLS_DIR)):
        if name.endswith(".html"):
            with open(os.path.join(TOOLS_DIR, name), encoding="utf-8") as fh:
                tools[name] = fh.read()

    tool_data_js = (
        "const TOOL_DATA = {\n"
        + ",\n".join(
            f"  {json.dumps(name)}: {safe_js_string(html)}"
            for name, html in tools.items()
        )
        + "\n};"
    )

    i0 = shell.find(OPEN_TOOL_START)
    if i0 == -1:
        print(f"ERROR: could not find {OPEN_TOOL_START!r} in {SOURCE}", file=sys.stderr)
        return 1
    i1 = shell.find(OPEN_TOOL_END, i0)
    if i1 == -1:
        print("ERROR: could not find end of openTool function", file=sys.stderr)
        return 1
    i1 += len(OPEN_TOOL_END)
    shell = shell[:i0] + BLOB_OPEN_TOOL + shell[i1:]

    last_script_close = shell.rfind("</script>")
    if last_script_close == -1:
        print("ERROR: no </script> found", file=sys.stderr)
        return 1
    shell = (
        shell[:last_script_close]
        + "\n"
        + tool_data_js
        + "\n"
        + shell[last_script_close:]
    )

    with open(OUTPUT, "w", encoding="utf-8") as fh:
        fh.write(shell)

    size_kb = os.path.getsize(OUTPUT) // 1024
    print(f"Built {os.path.basename(OUTPUT)} ({size_kb} KB · {len(tools)} tools embedded)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
