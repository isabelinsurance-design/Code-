"""
Agente IA · Medicare with Isabel — Telegram bot (código de referencia).

Habla con las mismas instrucciones que la app web (bot/isabel_system.txt lo genera
build.py desde index.html) y solo contesta a los chats autorizados.

Variables de entorno:
  TELEGRAM_BOT_TOKEN   token de @BotFather
  ANTHROPIC_API_KEY    llave de Anthropic
  ALLOWED_CHAT_IDS     ids de chat permitidos, separados por coma (manda /start al bot para ver el tuyo)
  ANTHROPIC_MODEL      opcional, por defecto claude-sonnet-5-5
  ANTHROPIC_EFFORT     opcional: low | medium | high (por defecto medium)

Ejecutar:  python bot.py
"""
import asyncio
import logging
import os
from datetime import date, datetime
from zoneinfo import ZoneInfo

import anthropic
from telegram import Update
from telegram.constants import ChatAction
from telegram.ext import Application, CommandHandler, ContextTypes, MessageHandler, filters

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("isabel-bot")

HERE = os.path.dirname(os.path.abspath(__file__))
MODEL = os.environ.get("ANTHROPIC_MODEL", "claude-sonnet-5-5")
EFFORT = os.environ.get("ANTHROPIC_EFFORT", "medium")
LA = ZoneInfo("America/Los_Angeles")
AEP_OPEN, AEP_CLOSE = date(2026, 10, 15), date(2026, 12, 7)
TELEGRAM_NOTE = (
    "\n- Estás respondiendo por Telegram: sé concisa (máximo ~10 párrafos), escribe en texto "
    "plano fácil de leer en el celular, sin tablas y sin encabezados con #."
)


def parse_ids(raw: str) -> set:
    out = set()
    for part in (raw or "").replace(" ", "").split(","):
        if part.lstrip("-").isdigit():
            out.add(int(part))
    return out


ALLOWED = parse_ids(os.environ.get("ALLOWED_CHAT_IDS", ""))

WELCOME = (
    "🦋 ¡Hola, Isabel! Soy tu Agente IA de *Medicare with Isabel*.\n\n"
    "Escríbeme cualquier pregunta — o usa un comando rápido:\n\n"
    "🎣 /hook _tema_ — 5 ganchos virales\n"
    "📹 /idea — 3 ideas de Reels\n"
    "🎙️ /live _tema_ — guion de Facebook Live\n"
    "💡 /tip — un tip '¿Sabías que…?'\n"
    "📘 /lead — copy para captura de leads\n"
    "❤️ /historia — plantilla de historia\n"
    "🚀 /semana — TODO lo de arriba en paralelo\n\n"
    "También puedes escribirme: _'dame 3 ideas para mañana'_ o _'qué digo en mi Live de hoy'_."
)

QUICK_PROMPTS = {
    "hook": (
        "Dame 5 GANCHOS virales en español para Reels de Facebook, sobre {tema}. "
        "Tono cálido del Sur de California, para personas de 60+. Cada gancho de 1 frase. "
        "Numera 1-5 y agrega 1 línea breve de por qué funciona."
    ),
    "idea": (
        "Dame 3 IDEAS de Reels virales para esta semana según la fase de AEP en la que estamos. "
        "Para cada uno: tema, gancho (3 seg), guion corto (30-45s), CTA, y 1 línea de por qué se compartiría."
    ),
    "live": (
        "Escribe un GUION de Facebook Live de 15 minutos en español para mi Q&A semanal "
        "sobre {tema}. Estructura: gancho inicial, bienvenida cálida, 3 puntos clave, "
        "preguntas de los comentarios, cierre con CTA 'Escríbeme MEDICARE'. Recuerda decir el disclaimer TPMO antes de hablar de beneficios."
    ),
    "tip": (
        "Escribe un post '¿Sabías que…?' en español para mi audiencia de 60+, "
        "sobre UN beneficio poco conocido de Medicare Advantage (según el plan y el área). Gancho + dato + "
        "explicación breve + CTA 'Escríbeme y te explico si tu plan lo incluye'."
    ),
    "lead": (
        "Escribe el COPY de un post de Facebook ofreciendo mi guía gratis "
        "'5 cosas que debes saber antes de elegir tu plan Medicare'. Tono cálido, "
        "español sencillo. Hook + 3 razones para pedirla + CTA 'Escríbeme MEDICARE'."
    ),
    "historia": (
        "Dame una PLANTILLA de historia inspiradora para Facebook que Isabel pueda "
        "completar con detalles reales de un cliente (con permiso por escrito). Deja [corchetes] "
        "donde hay que llenar. NO inventes nombres ni testimonios."
    ),
}
WEEK_TITLES = {
    "hook": "🎣 Ganchos Virales", "idea": "📹 Ideas de Reels", "live": "🎙️ Guion de Facebook Live",
    "tip": "💡 Tip ¿Sabías que…?", "lead": "📘 Captura de Leads", "historia": "❤️ Plantilla de Historia",
}

_client = None


def get_client() -> "anthropic.AsyncAnthropic":
    global _client
    if _client is None:
        _client = anthropic.AsyncAnthropic(api_key=os.environ["ANTHROPIC_API_KEY"])
    return _client


def system_base() -> str:
    with open(os.path.join(HERE, "isabel_system.txt"), encoding="utf-8") as fh:
        return fh.read().strip() + TELEGRAM_NOTE


def context_block(now=None) -> str:
    now = now or datetime.now(LA)
    today = now.date()
    dias = ["lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo"]
    meses = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto",
             "septiembre", "octubre", "noviembre", "diciembre"]
    lines = ["CONTEXTO DE HOY (úsalo para que lo que escribas sea actual; no lo repitas literal):",
             f"• Hoy es {dias[today.weekday()]} {today.day} de {meses[today.month - 1]} de {today.year} (hora de California)."]
    if today < AEP_OPEN:
        d = (AEP_OPEN - today).days
        lines.append(f"• AEP 2026 (15 oct – 7 dic) abre en {d} día{'' if d == 1 else 's'}. Ya se puede hacer marketing de los planes 2027; las aplicaciones empiezan el 15 de octubre.")
    elif today <= AEP_CLOSE:
        day, left = (today - AEP_OPEN).days + 1, (AEP_CLOSE - today).days
        lines.append(f"• AEP está ABIERTO: día {day} de 54, quedan {left} día{'' if left == 1 else 's'}. Meta del equipo: 39 aplicaciones por semana, 300 en total.")
    else:
        lines.append("• AEP 2026 ya cerró (7 dic). Del 1 de enero al 31 de marzo quien ya tiene Medicare Advantage puede hacer un cambio. Turning 65 sigue todo el año.")
    return "\n".join(lines)


async def call_claude(user_message: str, max_tokens: int = 8000) -> str:
    # Los modelos actuales piensan por defecto y ese "pensar" cuenta dentro de max_tokens:
    # es solo un tope (no cuesta más si no se usa), así que se deja holgado.
    msg = await get_client().messages.create(
        model=MODEL,
        max_tokens=max_tokens,
        system=system_base() + "\n\n" + context_block(),
        messages=[{"role": "user", "content": user_message}],
        extra_body={"output_config": {"effort": EFFORT}},
    )
    if getattr(msg, "stop_reason", None) == "refusal":
        return "No pude responder a eso. Prueba con otras palabras."
    text = "".join(b.text for b in msg.content if getattr(b, "type", "") == "text").strip()
    return text or "La IA no devolvió texto. Intenta de nuevo."


def friendly_error(e: Exception) -> str:
    if isinstance(e, anthropic.AuthenticationError):
        return "La llave de Anthropic no es válida. Avísale a quien administra el bot."
    if isinstance(e, anthropic.RateLimitError):
        return "Hay demasiadas solicitudes a la vez. Espera un minuto y vuelve a intentar."
    if isinstance(e, anthropic.NotFoundError):
        return ("El modelo de IA configurado ya no existe. Cambia ANTHROPIC_MODEL "
                "(ver platform.claude.com/docs/en/about-claude/model-deprecations).")
    if isinstance(e, anthropic.APIConnectionError):
        return "No pude conectar con la IA. Intenta de nuevo en un momento."
    if "credit balance" in str(e).lower():
        return "La cuenta de Anthropic no tiene saldo. Agrégalo en console.anthropic.com."
    return f"Algo salió mal: {e}"


def chunks(text: str, size: int = 3800) -> list:
    """Telegram rechaza mensajes de más de 4096 caracteres: partir por párrafos."""
    out, cur = [], ""
    for para in text.split("\n\n"):
        while len(para) > size:
            if cur:
                out.append(cur); cur = ""
            out.append(para[:size]); para = para[size:]
        if len(cur) + len(para) + 2 > size and cur:
            out.append(cur); cur = para
        else:
            cur = (cur + "\n\n" + para) if cur else para
    if cur:
        out.append(cur)
    return out or [""]


async def send_long(update: Update, text: str):
    for part in chunks(text):
        await update.message.reply_text(part)


def private(handler):
    """Solo los chats de ALLOWED_CHAT_IDS pueden usar el bot (si no, cualquiera que lo encuentre gasta tu saldo)."""
    async def wrapped(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
        chat = update.effective_chat
        if chat is None or chat.id not in ALLOWED:
            log.warning("chat bloqueado: %s", getattr(chat, "id", None))
            return
        return await handler(update, ctx)
    return wrapped


async def _typing(update: Update):
    await update.message.chat.send_action(ChatAction.TYPING)


async def cmd_start(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    chat = update.effective_chat
    if chat is None or chat.id not in ALLOWED:
        await update.message.reply_text(
            f"Este bot es privado. Tu chat id es {chat.id if chat else '?'}. "
            "Pídele a quien lo administra que lo agregue a ALLOWED_CHAT_IDS."
        )
        return
    await update.message.reply_text(WELCOME, parse_mode="Markdown")


def make_quick_handler(key: str, takes_topic: bool):
    async def handler(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
        await _typing(update)
        topic = " ".join(ctx.args).strip() if takes_topic and ctx.args else "Medicare en general"
        prompt = QUICK_PROMPTS[key].format(tema=topic) if takes_topic else QUICK_PROMPTS[key]
        try:
            txt = await call_claude(prompt)
        except Exception as e:  # noqa: BLE001
            log.exception("falló /%s", key)
            txt = friendly_error(e)
        await send_long(update, txt)
    return private(handler)


@private
async def cmd_semana(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    """Los seis a la vez: tu semana completa de borradores."""
    await _typing(update)
    await update.message.reply_text("🚀 Generando tu semana — 6 agentes trabajando en paralelo…")
    keys = list(WEEK_TITLES)
    prompts = [QUICK_PROMPTS[k].format(tema="Medicare en general") if "{tema}" in QUICK_PROMPTS[k] else QUICK_PROMPTS[k] for k in keys]
    results = await asyncio.gather(*[call_claude(p) for p in prompts], return_exceptions=True)
    for k, body in zip(keys, results):
        txt = friendly_error(body) if isinstance(body, Exception) else body
        await send_long(update, f"{WEEK_TITLES[k]}\n\n{txt}")
    await update.message.reply_text("✅ Listo — copia lo que te sirva y a publicar 🦋")


@private
async def on_text(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    await _typing(update)
    try:
        txt = await call_claude(update.message.text)
    except Exception as e:  # noqa: BLE001
        log.exception("falló el texto libre")
        txt = friendly_error(e)
    await send_long(update, txt)


def main():
    if not ALLOWED:
        log.warning("ALLOWED_CHAT_IDS está vacío: el bot solo responderá a /start con tu chat id. "
                    "Agrégalo y reinicia.")
    app = Application.builder().token(os.environ["TELEGRAM_BOT_TOKEN"]).build()
    app.add_handler(CommandHandler(["start", "help"], cmd_start))
    for key, takes_topic in [("hook", True), ("idea", False), ("live", True), ("tip", False), ("lead", False), ("historia", False)]:
        app.add_handler(CommandHandler(key, make_quick_handler(key, takes_topic)))
    app.add_handler(CommandHandler("semana", cmd_semana))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, on_text))
    log.info("Bot Isabel iniciado (modelo %s, esfuerzo %s) — esperando mensajes…", MODEL, EFFORT)
    app.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
