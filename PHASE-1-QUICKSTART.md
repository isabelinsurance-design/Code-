# FASE 1 — Subir Marketing a LUNA (5 minutos)

Single-file drop-in. Solo necesitas acceso a Bluehost cPanel + el repo/HTML
de LUNA. Esto pone el agente Marketing vivo dentro de LUNA hoy mismo.

## Lo que necesitas tener a mano
- Login a **Bluehost cPanel** de LUNA
- El archivo **`isabel-sistema-completo-UNICO.html`** (te lo paso Isabel)
- 5 minutos

## Paso 1 — Subir el archivo (2 min)
1. Entra a **cPanel → File Manager**
2. Navega a la carpeta donde LUNA sirve su HTML estático
   (típicamente `public_html/luna/` o `public_html/`)
3. Crea un sub-folder llamado **`marketing/`**
4. Click **Upload** → selecciona `isabel-sistema-completo-UNICO.html` → sube.

URL resultante (memorízala para Paso 2):
`https://[dominio-de-luna]/marketing/isabel-sistema-completo-UNICO.html`

## Paso 2 — Agregar el link en la nav de LUNA (2 min)
En el menú de navegación de LUNA, agrega un item nuevo:

```html
<a href="/marketing/isabel-sistema-completo-UNICO.html"
   class="luna-nav-item">
  🦋 Marketing
</a>
```

(Adapta `class` y formato a como están los otros items del menú de LUNA.)

## Paso 3 — Confirmar con Isabel (1 min)
Mándale el link a Isabel. Ella abre LUNA → click "Marketing" → confirma que
ve el sistema. Pega su Anthropic API key arriba a la derecha y empieza a
usarlo.

---

## ✅ Listo

Después de estos 3 pasos:
- El agente Marketing está vivo dentro de LUNA
- Isabel ve y usa todo: Plan, Marca, Plantillas, Viral, Memoria, Equipo IA,
  Radar, Pregunta Inteligente, Agente Móvil + las 18 herramientas
- Sus datos quedan en el navegador (single-device por ahora — eso lo
  arregla Fase 2)

## ⏭️ Lo que sigue
Fases 2 (sync MySQL), 3 (briefings automáticos) y 4 (registrar como agente
del orchestrator) están en **`MERGE-TO-LUNA.md`** con todos los detalles.
Hazlas a tu ritmo. Solo Fase 1 es urgente: con eso Isabel ya puede empezar
a trabajar.

---

Cualquier duda técnica está en `CLAUDE.md` y los blueprints PHP en
`PARA-LUNA-TEAM.md`. 🦋
