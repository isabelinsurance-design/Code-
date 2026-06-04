# CRM — Medicare with Isabel

CRM en **PHP + MySQL** para gestión de miembros Medicare, ventas, retención,
finanzas y leads de Facebook, con asistente de IA (Isabel AI).

## 📚 Documentación
- **[`EVALUACION.md`](./EVALUACION.md)** — evaluación de la estructura y separación front/back.
- **[`GUIA.md`](./GUIA.md)** — cómo trabajar sin el File Manager de Bluehost (GitHub + Docker + deploy).
- **[`database/LEEME.md`](./database/LEEME.md)** — cómo traer el esquema de la base de datos.

## 🗂️ Estructura
```
crm/        → la aplicación (PHP). Esta carpeta es el "web root".
database/   → esquema de la base de datos (los .sql con datos NO se suben).
docker-compose.yml → entorno local (PHP + MySQL + phpMyAdmin).
```

## ⚡ Arrancar en local (resumen)
```bash
cp crm/config.example.php crm/config.php   # y rellena tus claves
docker compose up                          # → http://localhost:8080
```

## 🔐 Seguridad
- `crm/config.php` (claves reales) **nunca se sube** — está en `.gitignore`.
- Usa `crm/config.example.php` como plantilla.
- Si clonaste claves viejas: revócalas/rótalas (ver Paso 0 de `GUIA.md`).
