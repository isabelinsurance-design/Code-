#!/usr/bin/env bash
# Inicia el Sistema Maestro de Isabel Fuentes en un servidor local.
# Uso:  ./serve.sh   (luego abre http://localhost:8137 en tu navegador)
# Servir por http (no file://) permite que la API Key se comparta entre todas las herramientas.
cd "$(dirname "$0")"
PORT="${1:-8137}"
echo "Sistema Maestro Isabel → http://localhost:$PORT"
python3 -m http.server "$PORT"
