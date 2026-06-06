"""
Agente IA · Medicare with Isabel — Telegram bot.

Lets Isabel talk to her marketing AI from her phone and give it tasks:
content ideas, viral hooks, Live scripts, lead-magnet copy, etc.

Requires env vars: TELEGRAM_BOT_TOKEN, ANTHROPIC_API_KEY.
Run: python bot.py
"""
import os
import logging

from anthropic import Anthropic
from telegram import Update
from telegram.constants import ChatAction
from telegram.ext import (
    Application,
    CommandHandler,
    MessageHandler,
    ContextTypes,
    filters,
)

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger("isabel-bot")

ANTHROPIC_API_KEY = os.environ["ANTHROPIC_API_KEY"]
TELEGRAM_BOT_TOKEN = os.environ["TELEGRAM_BOT_TOKEN"]
MODEL = "claude-sonnet-4-20250514"

client = Anthropic(api_key=ANTHROPIC_API_KEY)

ISABEL_SYSTEM = """Eres el asistente IA de Isabel Fuentes, agente licenciada de Medicare en California.
Isabel es bilingüe (inglés/español), trabaja con SCAN, Anthem, Humana, Alignment, LA Care, Health Net, Molina y United Healthcare.
Su mercado es el sur de California (Los Ángeles, Orange County, Inland Empire), especializada en el mercado hispano mayor de 60 años.
Website: withisabelfuentes.com

MISIÓN (tenla siempre presente):
Ayudar a Isabel a (1) hacerse VIRAL en Facebook con contenido orgánico (Reels y Lives sobre todo),
(2) convertir esa atención en LEADS baratos y luego en MIEMBROS inscritos, y
(3) convertirse en LA agente/broker de Medicare más reconocida del mercado latino en el Sur de California.
Cada idea que des debe acercarla a esa meta: contenido que detiene el scroll, genera comentarios y se comparte.

Siempre:
- Da respuestas prácticas, accionables y específicas para el mercado hispano
- Prioriza formatos que se vuelven virales y son baratos: Reels, Facebook Lives, videos cortos hablando a cámara
- Empieza con un GANCHO fuerte en los primeros 3 segundos y termina con una llamada a la acción clara
- Usa tono cálido, familiar, en español cuando sea apropiado
- Incluye emojis estratégicamente (no excesivos)
- Recuerda las reglas CMS: no promesas absolutas, no garantías, incluir disclaimers cuando sea necesario
- Si generas copy de marketing, siempre nota si necesita disclaimer de carrier
- Como estás respondiendo en Telegram, mantén las respuestas concisas (máximo ~10 párrafos) y bien formateadas para leer en el celular."""


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
    "También puedes simplemente escribirme: _'dame 3 ideas para mañana'_ "
    "o _'qué digo en mi Live de hoy'_."
)

QUICK_PROMPTS = {
    "hook": (
        "Dame 5 GANCHOS virales en español para Reels de Facebook, sobre {tema}. "
        "Tono cálido del Sur de California, mercado hispano 60+. Cada gancho de 1 frase. "
        "Numera 1-5 y agrega 1 línea breve de por qué funciona."
    ),
    "idea": (
        "Dame 3 IDEAS de Reels virales para esta semana, mercado latino Medicare SoCal. "
        "Para cada uno: tema, gancho (3 seg), guion corto (30-45s), CTA, "
        "y 1 línea de por qué se compartiría."
    ),
    "live": (
        "Escribe un GUION de Facebook Live de 5-7 minutos en español para mi Q&A semanal "
        "sobre {tema}. Estructura: gancho inicial, bienvenida cálida, 3 puntos clave, "
        "invitación a comentar dudas, cierre con CTA 'Escríbeme MEDICARE y te mando mi guía gratis'."
    ),
    "tip": (
        "Escribe un post '¿Sabías que…?' en español para mi audiencia latina 60+, "
        "sobre UN beneficio poco conocido de Medicare Advantage. Gancho + dato + "
        "explicación breve + CTA 'Escríbeme y te explico si tu plan lo incluye 💙'."
    ),
    "lead": (
        "Escribe el COPY de un post de Facebook ofreciendo mi guía gratis "
        "'5 cosas que debes saber antes de elegir tu plan Medicare'. Tono cálido, "
        "español sencillo. Hook + 3 razones para descargarla + CTA 'Escríbeme MEDICARE'."
    ),
    "historia": (
        "Dame una PLANTILLA de historia inspiradora para Facebook que Isabel pueda "
        "completar con detalles reales de un cliente (con permiso). Deja [corchetes] "
        "donde hay que llenar. NO inventes nombres ni testimonios."
    ),
}


def call_claude(user_message: str, max_tokens: int = 1024) -> str:
    msg = client.messages.create(
        model=MODEL,
        max_tokens=max_tokens,
        system=ISABEL_SYSTEM,
        messages=[{"role": "user", "content": user_message}],
    )
    return "".join(b.text for b in msg.content if hasattr(b, "text"))


async def _typing(update: Update):
    await update.message.chat.send_action(ChatAction.TYPING)


async def cmd_start(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    await update.message.reply_text(WELCOME, parse_mode="Markdown")


def make_quick_handler(key: str, takes_topic: bool):
    async def handler(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
        await _typing(update)
        topic = " ".join(ctx.args).strip() if takes_topic and ctx.args else "Medicare en general"
        prompt = QUICK_PROMPTS[key].format(tema=topic) if takes_topic else QUICK_PROMPTS[key]
        try:
            txt = call_claude(prompt)
        except Exception as e:
            log.exception("claude call failed for /%s", key)
            txt = f"❌ Error: {e}"
        await update.message.reply_text(txt)
    return handler


async def cmd_semana(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    """Fire all six quick prompts in parallel — Isabel's 'whole week in one tap'."""
    import asyncio
    await _typing(update)
    await update.message.reply_text("🚀 Generando tu semana — 6 agentes trabajando en paralelo…")
    keys = ["hook", "idea", "live", "tip", "lead", "historia"]
    titles = {
        "hook": "🎣 *Ganchos Virales*",
        "idea": "📹 *Ideas de Reels*",
        "live": "🎙️ *Guion de Facebook Live*",
        "tip": "💡 *Tip ¿Sabías que…?*",
        "lead": "📘 *Captura de Leads*",
        "historia": "❤️ *Plantilla de Historia*",
    }
    loop = asyncio.get_running_loop()

    def fire(k: str) -> str:
        prompt = QUICK_PROMPTS[k].format(tema="Medicare en general") if "{tema}" in QUICK_PROMPTS[k] else QUICK_PROMPTS[k]
        try:
            return call_claude(prompt, max_tokens=900)
        except Exception as e:
            return f"❌ {e}"

    results = await asyncio.gather(*[loop.run_in_executor(None, fire, k) for k in keys])
    for k, body in zip(keys, results):
        await update.message.reply_text(f"{titles[k]}\n\n{body}", parse_mode="Markdown")
    await update.message.reply_text("✅ Listo — copia lo que te sirva y a publicar 🦋")


async def on_text(update: Update, ctx: ContextTypes.DEFAULT_TYPE):
    await _typing(update)
    try:
        txt = call_claude(update.message.text)
    except Exception as e:
        log.exception("claude call failed for free text")
        txt = f"❌ Error: {e}"
    await update.message.reply_text(txt)


def main():
    app = Application.builder().token(TELEGRAM_BOT_TOKEN).build()
    app.add_handler(CommandHandler(["start", "help"], cmd_start))

    quick_specs = [("hook", True), ("idea", False), ("live", True),
                   ("tip", False), ("lead", False), ("historia", False)]
    for key, takes_topic in quick_specs:
        app.add_handler(CommandHandler(key, make_quick_handler(key, takes_topic)))

    app.add_handler(CommandHandler("semana", cmd_semana))
    app.add_handler(MessageHandler(filters.TEXT & ~filters.COMMAND, on_text))

    log.info("Bot Isabel iniciado — esperando mensajes…")
    app.run_polling(allowed_updates=Update.ALL_TYPES)


if __name__ == "__main__":
    main()
