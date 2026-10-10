# Poner a SAMIA en vivo — lista simple (para Isabel o Sami)

Esto pone a SAMIA en el internet, corriendo 24/7, para que el equipo la use y para
que su briefing de la mañana y el Radar prendan solos. Es una vez. ~10 minutos, casi
todo es dar clic. No necesitas saber de código.

> Vas a usar **Railway** (railway.app) — es como un "Bluehost para apps". La página de
> SAMIA (LUNA) sigue en Bluehost; esto no la toca.

---

## Antes de empezar, ten a la mano
- Tu cuenta de GitHub (donde vive el código de SAMIA).
- Tu **API key de Anthropic** (empieza con `sk-ant-...`). Si no la tienes: console.anthropic.com → API Keys → Create Key. Asegúrate de tener **saldo** (Billing).

---

## Pasos

**1. Crear el proyecto**
- Entra a **railway.app** y haz login con GitHub.
- Clic **New Project** → **Deploy from GitHub repo**.
- Elige el repo **`Code-`** y la rama **`claude/great-davinci-OWzcr`**.
- Railway empieza a construir solo. Déjalo.

**2. Poner las variables** (la sección **Variables**)
Agrega estas, una por una (nombre = valor):

| Nombre (copia tal cual) | Valor |
|---|---|
| `ANTHROPIC_API_KEY` | tu `sk-ant-...` |
| `DATA_DIR` | `/data` |
| `TZ` | `America/Los_Angeles` |

> NO pongas `PORT`. Railway lo maneja solo.

**3. Darle una dirección web**
- **Settings** → **Networking** → **Generate Domain**.
- Te da algo como `samia-production.up.railway.app`. **Esa es la dirección de SAMIA.**

**4. Memoria que no se borra** (importante)
- **Variables / Storage** → **New Volume** → Mount path: escribe **`/data`**.
- (Esto hace que lo que SAMIA aprende/guarda sobreviva cuando se reinicia. Debe ser el
  mismo `/data` de la variable del paso 2.)

**5. Probar que vive**
- Abre `https://TU-DIRECCION.up.railway.app/` → debe aparecer la **escuela**.
- Abre `https://TU-DIRECCION.up.railway.app/dashboard` → el **panel**.
- En el panel, sección **Sistema**: el scheduler debe decir **activo**.

¡Listo! SAMIA está en vivo.

---

## Si algo sale mal
- **Sale "NO_API_KEY" o no contesta en el chat** → falta la key o no tiene saldo
  (paso 2 + Billing en Anthropic).
- **"Not found" al abrir** → espera 1-2 min a que termine de construir, y confirma que
  generaste el dominio (paso 3).
- **SAMIA "olvida" cosas tras un reinicio** → falta el volumen en `/data` (paso 4).

## Opcional (cuando quieras): que viva en tu dominio
Si prefieres `samia.withisabelfuentes.com` en vez de la dirección de railway.app, es un
cambio chico de DNS (un CNAME). Dime y te paso el paso exacto — tu equipo abre *tu*
dirección y ni se entera que Railway está detrás.

## Después de que viva: terminar lo de AEP 2027
Con la key puesta, ya se puede llenar lo que quedó marcado en `docs/AEP-AUDIT.md`
(montos y números de plan 2027). Dime "dale AEP" y lo corro con el Radar de currículo.
