#!/usr/bin/env bash
# Inicia el Sistema Maestro de Isabel Fuentes en un servidor local.
#
# OJO: SAMIA (samia.html) ahora necesita su backend (Node) para funcionar, porque
# la API key vive en el servidor, no en el navegador. Para SAMIA usa:
#
#     cp .env.example .env   # y pon tu ANTHROPIC_API_KEY
#     npm start              # sirve TODO (index.html, tools/, samia.html) + /api
#
# Este script (python http.server) sigue sirviendo las herramientas estaticas que
# NO usan backend, pero con el las llamadas /api/chat de SAMIA fallaran.
cd "$(dirname "$0")"
PORT="${1:-8137}"
echo "Estatico (sin backend) → http://localhost:$PORT"
echo "Para SAMIA con backend usa:  npm start"
python3 -m http.server "$PORT"
