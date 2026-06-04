# CRM Isabel Fuentes — Medicare Marketing

Sistema CRM/marketing con **frontend estático** y **backend serverless seguro**,
desplegado con **GitHub + Vercel**.

- 📁 `public/` → frontend (lo que ve el navegador)
- 🔒 `api/` → backend (esconde la API Key de Anthropic)
- 📘 **[Lee la guía completa: `GUIA.md`](./GUIA.md)**

## Inicio rápido
1. Sube tus archivos `.html` a `public/` y `public/tools/`.
2. Asegúrate de que llamen a `/api/claude` (no a `api.anthropic.com`).
3. En Vercel, configura la variable `ANTHROPIC_API_KEY`.
4. Cada cambio que subas a GitHub se publica solo.

Ver `GUIA.md` para el paso a paso detallado.
