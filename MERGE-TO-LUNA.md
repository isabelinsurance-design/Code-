# Cómo fusionar el Sistema de Marketing dentro de LUNA

Documento de fusión (merge runbook). El sistema de marketing pasa a ser un
**agente más de LUNA**, no un sistema aparte.

---

## Para Isabel (resumen ejecutivo)

**Qué pasa:** todo el sistema de marketing que construimos (Plan, Marca,
Plantillas, Viral, Memoria, Equipo IA, Radar, etc.) entra a vivir adentro de
LUNA. Tú vas a abrir LUNA como siempre, y vas a tener un nuevo agente
"Marketing" en tu navegación.

**Quién hace qué:**
- **Sammy / equipo técnico** → ejecuta este runbook (4 fases). Trabajo técnico real.
- **Tú** → en Fase 1 nada más confirmas que ves el sistema dentro de LUNA. En
  Fase 3 empiezas a recibir briefings automáticos a tu Telegram. Listo.

**Cuánto tarda:**
- Fase 1 (drop-in): 15 minutos, hoy mismo
- Fase 2 (datos sincronizados): 1-2 semanas
- Fase 3 (briefings automáticos): 1 semana después
- Fase 4 (orquestador): 1-2 semanas más, opcional

Cuando termine Fase 3, tienes el sistema completo de marketing dentro de LUNA
+ briefings automáticos cada mañana. **Eso es el momento "Athena-like".**

---

## Para Sammy (runbook técnico, 4 fases)

### Antes de empezar
Necesitas:
- Acceso a Bluehost cPanel (donde vive LUNA)
- Acceso a MySQL de LUNA
- Una Anthropic API key (la de Isabel, en `sk-ant-...`)
- (Para Fase 3) Un bot de Telegram nuevo, creado vía [@BotFather](https://t.me/BotFather) → `/newbot`

Toda la documentación técnica de patrones está en:
- `CLAUDE.md` — arquitectura y convenciones del browser app
- `PARA-LUNA-TEAM.md` — schemas SQL + skeletons PHP listos

---

### 🟢 Fase 1 — Drop-in (15 minutos · Día 1)

El camino más rápido para tener el sistema VIVO dentro de LUNA. Sin
integración de datos todavía, pero funciona.

1. **Subir el archivo único.** Sube `isabel-sistema-completo-UNICO.html` al
   folder `public/marketing/` (o donde LUNA sirve su HTML estático) vía cPanel
   File Manager.
2. **Agregar el link en LUNA.** En la navegación principal de LUNA, agrega
   `Marketing → /marketing/isabel-sistema-completo-UNICO.html`.
3. **Probar.** Isabel abre LUNA → click "Marketing" → ve el sistema completo.
   Pega su Anthropic API key arriba a la derecha → todo funciona en navegador.

**Qué funciona después de Fase 1:**
- Todas las pestañas (Plan, Marca, Plantillas, Viral, Memoria, Equipo IA,
  Pregunta Inteligente, Radar, Agente Móvil)
- Las 18 herramientas standalone
- Los patrones Athena ya horneados (memoria, capture-by-default, voces,
  compliance gating, salud, gaps, Radar con 5 lentes y self-grading)

**Qué NO funciona aún:**
- Los datos viven en el navegador (no se ven en otro dispositivo)
- No hay briefings automáticos
- No hay Radar semanal automático

---

### 🟡 Fase 2 — Integración de datos (1-2 semanas)

Mover memoria, plan, leads y audit log del `localStorage` del navegador a
MySQL en Bluehost. Así Isabel ve lo mismo en teléfono y laptop, y otros
agentes de LUNA pueden leer la memoria de marketing.

1. **Crear las tablas MySQL.** Ejecuta los `CREATE TABLE` que están en
   `PARA-LUNA-TEAM.md` (Blueprint PHP para Bluehost):
   - `luna_memoria` (hechos, personas, tareas, compromisos)
   - `luna_intel` (Radar runs con snapshot estructurado)
   - `luna_drafts`, `luna_audit`
2. **Crear los endpoints PHP** dentro de LUNA (autenticados con la sesión de
   LUNA — usa el sistema de auth que ya tienen):

   | Método | Path | Qué hace |
   |---|---|---|
   | `GET`  | `/api/mkt/memoria` | Devuelve `{hechos, personas, tareas, compromisos}` |
   | `POST` | `/api/mkt/memoria/:layer` | Inserta item en la capa |
   | `DELETE` | `/api/mkt/memoria/:layer/:id` | Borra item |
   | `GET`  | `/api/mkt/leads` | Lista de leads del CRM |
   | `POST` | `/api/mkt/leads` | Agrega lead |
   | `GET`  | `/api/mkt/intel` | Últimas 20 Radar runs con snapshot |
   | `POST` | `/api/mkt/intel` | Guarda nueva run (text + snapshot) |
   | `POST` | `/api/mkt/audit` | Append-only audit event |

3. **Inyectar un adaptador en el HTML.** Al inicio del `<script>` principal,
   agrega ~25 líneas que reemplazan las llamadas a `localStorage` por
   `fetch('/api/mkt/...')`. Mantiene el `localStorage` como caché optimista
   (write-through):

   ```js
   const _origGetItem = localStorage.getItem.bind(localStorage);
   const _origSetItem = localStorage.setItem.bind(localStorage);
   const SYNCED = ['isabel_memoria_hechos','isabel_memoria_personas',
     'isabel_memoria_tareas','isabel_memoria_compromisos',
     'isabel_crm_leads','isabel_intel_runs','isabel_plan_progress',
     'isabel_audit_log'];
   // Al cargar: hidratar desde el servidor
   async function hydrateFromServer(){
     for(const key of SYNCED){
       try {
         const layer = key.replace('isabel_','').replace('memoria_','');
         const r = await fetch('/api/mkt/' + layer);
         if(r.ok){ _origSetItem(key, await r.text()); }
       } catch(_){}
     }
   }
   // Al guardar: write-through al servidor
   localStorage.setItem = function(key, val){
     _origSetItem(key, val);
     if(SYNCED.includes(key)){
       const layer = key.replace('isabel_','').replace('memoria_','');
       fetch('/api/mkt/' + layer, {method:'POST', body: val,
         headers:{'Content-Type':'application/json'}}).catch(()=>{});
     }
   };
   hydrateFromServer();
   ```

4. **Probar el sync.** Abre marketing en teléfono → agrega un hecho a Memoria →
   abre en laptop → debe aparecer.

**Qué funciona después de Fase 2:**
- Datos sincronizados multi-dispositivo
- LUNA's main UI puede leer la memoria de marketing y mostrarla en otros lados
- Isabel puede borrar caché del navegador sin perder nada

---

### 🔵 Fase 3 — Crons automáticos (1 semana)

Aquí entra la magia "Athena-like": briefings matutinos + Radar semanal
empujados a Telegram sin que Isabel haga clic.

1. **Crear el bot de Telegram.** Abre Telegram, busca `@BotFather`, escribe
   `/newbot` → ponle nombre "LUNA Isabel" → copia el TOKEN.
2. **Configurar el bot.** Isabel le escribe `/start` al bot. El bot debe
   responder con un chat_id — guarda ese chat_id en `config.php` de LUNA
   como `ISABEL_CHAT_ID`.
3. **Subir los crons.** En `cron/` de LUNA, sube:
   - `briefing.php` (skeleton en `PARA-LUNA-TEAM.md`)
   - `intel-semanal.php` (skeleton en `PARA-LUNA-TEAM.md`)
   - Asegúrate que ambos requieran `config.php` con `ANTHROPIC_KEY`,
     `TELEGRAM_BOT_TOKEN`, `ISABEL_CHAT_ID`, `$PDO`
4. **Programar en cPanel.** cPanel → Cron Jobs → agrega:
   - `30 6 * * *` → `/usr/bin/php /home/USER/luna/cron/briefing.php`
   - `0 6 * * 1` → `/usr/bin/php /home/USER/luna/cron/intel-semanal.php`
5. **Probar manualmente.** Antes de esperar al cron, ejecuta cada PHP a mano
   desde SSH para verificar que Isabel recibe el mensaje en Telegram.

**Qué funciona después de Fase 3:**
- Isabel recibe **briefing diario** 6:30am SoCal en Telegram (resumen + gaps
  + propuesta del día + ✅ próxima acción)
- Isabel recibe **Radar semanal** lunes 6am con las 5 lentes + Chief of
  Staff (incluye self-grade vs semana anterior y sugerencia de mejora al
  sistema)
- **Este es el "Athena moment".** Ahora Isabel no abre el sistema buscando
  qué hacer — el sistema le dice qué hacer.

---

### 🟣 Fase 4 — Convertir en agente de LUNA (opcional, 1-2 semanas)

Hasta aquí, marketing es una **sección** de LUNA. En Fase 4 se convierte en
un **agente** que el orchestrator principal de LUNA puede invocar como a
los otros 10.

1. **Registrar marketing como agente** en LUNA's agent registry junto con
   los otros 10. Definir su `desc`, `voice`, y los 6 sub-coaches (Ganchos,
   Live, Reels, Tip, Lead, Historia) como sus capacidades.
2. **Exponer los coaches como tools.** Cuando el orchestrator principal de
   LUNA recibe una pregunta tipo "dame ideas para un Reel viral", debe
   poder rutear al coach correcto del agente Marketing.
3. **Compartir el COACHES roster.** El const `COACHES` del HTML
   (`index.html`) ya está estructurado para ser portable — cópialo a
   `agents/marketing/coaches.php` y conviértelo a array PHP.

**Qué funciona después de Fase 4:**
- Isabel le pregunta a LUNA cualquier cosa de marketing en su UI principal,
  el orchestrator rutea automáticamente al coach correcto.
- Marketing es indistinguible de los otros 10 agentes en cuanto a cómo se
  invoca.

---

## Qué hacer con el bot Python (`bot/`)

El folder `bot/` tiene una implementación en Python del bot de Telegram
(handlers, prompts, captura). **No lo subas a Bluehost** — no corre ahí.

**Úsalo como referencia.** Cuando implementes `webhook-telegram.php` en
Fase 3, copia los prompts (`QUICK_PROMPTS`, `ISABEL_SYSTEM`) de
`bot/bot.py` al PHP. Los prompts son idénticos al browser app, así que la
voz se mantiene consistente.

Cuando termines Fase 3, puedes archivar o borrar `bot/` del repo.

---

## Checklist de migración (para Sammy)

### Día 1
- [ ] Fase 1 completa: Isabel ve el sistema dentro de LUNA

### Semana 1-2
- [ ] 4 tablas MySQL creadas (`luna_memoria`, `luna_intel`, `luna_drafts`, `luna_audit`)
- [ ] Endpoints PHP funcionando para memoria, leads, intel, audit
- [ ] Adaptador inyectado en HTML, sync probado en 2 dispositivos

### Semana 3
- [ ] Bot Telegram creado + token guardado en `config.php`
- [ ] `briefing.php` + `intel-semanal.php` subidos
- [ ] Crons cPanel programados
- [ ] Isabel recibió primer briefing manual desde SSH
- [ ] Isabel recibió primer briefing automático 6:30am día siguiente

### Semana 4-5 (opcional)
- [ ] Marketing registrado como agente en LUNA's orchestrator
- [ ] Pregunta de marketing en LUNA's main UI rutea correctamente

---

## Mantenimiento después del merge

Para editar features del agente Marketing:
1. Editar `index.html` en el repo
2. Re-generar `isabel-sistema-completo-UNICO.html` con el script Python
   descrito en `CLAUDE.md` (sección "Build step")
3. Subir el UNICO actualizado al folder `public/marketing/` de LUNA
4. Si tocaste tablas o endpoints, también actualizar PHP

Para agregar nuevos coaches/voces:
- Editar el `COACHES` const en `index.html` (ver `CLAUDE.md` sección
  "Multi-coach orchestrator"). Tanto `runEquipoIA` como `runOrchestrator`
  los usan automáticamente. En Fase 4 también actualizar el espejo PHP.

Para ajustar el Radar:
- Prompt en `runIntel()` dentro de `index.html`
- Cron equivalente en `cron/intel-semanal.php`
- Mantenlos sincronizados para que browser y servidor produzcan el mismo
  reporte.

---

🦋 *Bienvenido al equipo, agente Marketing.*
