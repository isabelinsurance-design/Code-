# Isabel — Telegram Bot

Single-file Telegram bot that wraps Anthropic Claude with the same
`ISABEL_SYSTEM` prompt as the web app (mission: viral on Facebook → cheap
leads → recognized Latino Medicare authority in SoCal).

## Commands

| Command | What it does |
|---|---|
| `/start`, `/help` | Welcome + command list |
| `/hook <tema>` | 5 viral hooks for Reels on a topic |
| `/idea` | 3 Reels ideas with hook + CTA |
| `/live <tema>` | Full Facebook Live script (5–7 min) |
| `/tip` | "¿Sabías que…?" educational post |
| `/lead` | Copy for the free-guide lead magnet |
| `/historia` | Story template (with `[brackets]` for real details) |
| `/semana` | Runs all six in parallel — a full week of drafts |
| _(free text)_ | Conversational Q&A with the brain |

## Run locally

```
pip install -r requirements.txt
export TELEGRAM_BOT_TOKEN=...   # from @BotFather
export ANTHROPIC_API_KEY=sk-ant-...
python bot.py
```

## Deploy (recommended: Railway)

1. Create the bot in Telegram: open [@BotFather](https://t.me/BotFather) → `/newbot` → copy the token.
2. Sign up at [railway.app](https://railway.app) → New Project → Deploy from GitHub repo → pick this repo.
3. In **Settings → Root Directory** set `bot`.
4. Set the **Start Command** to `python bot.py`.
5. Add **Variables**:
   - `TELEGRAM_BOT_TOKEN` = the @BotFather token
   - `ANTHROPIC_API_KEY` = `sk-ant-...`
6. Deploy. Open Telegram, message the bot, send `/start`.

Replit, Render, Fly.io, and any always-on Python host work equally well.

## Adding WhatsApp later

WhatsApp Business API needs business verification + approved templates, so
ship Telegram first. When ready, swap the Telegram handlers for a webhook
that reads the WhatsApp Cloud API (or use Twilio's WhatsApp sandbox for
testing). `call_claude()` and the prompts stay exactly the same.
