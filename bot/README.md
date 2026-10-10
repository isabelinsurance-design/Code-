# Isabel — Telegram Bot (reference code)

Telegram bot that wraps Anthropic Claude with the **same `ISABEL_SYSTEM` prompt
as the web app** (mission: viral on Facebook → cheap leads → recognized Latino
Medicare authority in SoCal). It knows today's date and where we are in AEP, and
it follows the CMS plan-year 2027 rules (no 48-hour SOA wait, TPMO disclaimer
before benefits, no SHIP line).

> **⚠️ Heads up for the LUNA team:** the main marketing system lives in LUNA on
> Bluehost. This bot is **Python** and won't run on Bluehost shared hosting.
> Use it as a reference for the prompts/commands and decide:
> (a) absorb into Athena on Railway (recommended — Athena already does
> conversational AI for Isabel), (b) re-implement as a PHP webhook on Bluehost,
> or (c) deploy this Python version separately on Railway/Replit/Render. See
> `../PARA-LUNA-TEAM.md`.

## Files

| File | What it is |
|---|---|
| `bot.py` | The bot (async, `python-telegram-bot` 22 + `anthropic` SDK). |
| `isabel_system.txt` | The system prompt. **Generated** by `python3 build.py` from `index.html` — don't hand-edit; it is committed so the bot folder deploys on its own. |
| `test_bot.py` | Offline tests (no Telegram, no API calls): `python bot/test_bot.py`. |
| `requirements.txt` | Pinned versions (`python-telegram-bot==22.8`, `anthropic==1.13.0`). |
| `.env.example` | The variables below. |

## Commands

| Command | What it does |
|---|---|
| `/start`, `/help` | Command list. To anyone who is **not** allowed it only shows their chat id. |
| `/hook <tema>` | 5 viral hooks for Reels on a topic |
| `/idea` | 3 Reels ideas with hook + CTA |
| `/live <tema>` | Facebook Live script |
| `/tip` | "¿Sabías que…?" educational post |
| `/lead` | Copy for the free-guide lead magnet |
| `/historia` | Story template (with `[brackets]` for real details) |
| `/semana` | Runs all six at the same time — a full week of drafts (6 API calls) |
| _(free text)_ | Conversational Q&A with the brain |

## Settings (environment variables)

| Variable | Required | Meaning |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` | yes | From [@BotFather](https://t.me/BotFather). |
| `ANTHROPIC_API_KEY` | yes | `sk-ant-…` — give it a monthly spend limit in console.anthropic.com. |
| `ALLOWED_CHAT_IDS` | yes | Comma-separated Telegram chat ids that may use the bot. **Without it the bot answers nobody** (so strangers can't spend Isabel's budget). |
| `ANTHROPIC_MODEL` | no | Default `claude-sonnet-5-5`. |
| `ANTHROPIC_EFFORT` | no | `low` / `medium` (default) / `high`. |

**How to get your chat id:** start the bot with `ALLOWED_CHAT_IDS` empty, send it
`/start`, and it replies with your id. Put that number in `ALLOWED_CHAT_IDS`
and restart.

## Run locally

```
pip install -r requirements.txt
export TELEGRAM_BOT_TOKEN=...   # from @BotFather
export ANTHROPIC_API_KEY=sk-ant-...
export ALLOWED_CHAT_IDS=123456789
python bot.py
```

## Deploy (recommended: Railway)

1. Create the bot in Telegram: open [@BotFather](https://t.me/BotFather) → `/newbot` → copy the token.
2. Sign up at [railway.app](https://railway.app) → New Project → Deploy from GitHub repo → pick this repo.
3. In **Settings → Root Directory** set `bot`.
4. Set the **Start Command** to `python bot.py`.
5. Add **Variables**: `TELEGRAM_BOT_TOKEN`, `ANTHROPIC_API_KEY`, and `ALLOWED_CHAT_IDS` (see above).
6. Deploy. Open Telegram, message the bot, send `/start` (first time: copy your chat id into `ALLOWED_CHAT_IDS`, redeploy).

Replit, Render, Fly.io, and any always-on Python host work equally well.

## Keeping it current

- After changing the prompt in `index.html`, run `python3 build.py` — it rewrites `bot/isabel_system.txt` (and the UNICO file).
- Models retire. The default model ID lives in one place (`MODEL` in `bot.py`, or the `ANTHROPIC_MODEL` variable, so you can change it without editing code). Check
  https://platform.claude.com/docs/en/about-claude/model-deprecations before assuming an ID still works.
- Current models "think" before answering and that thinking counts toward `max_tokens`, so the bot allows 8000 (it's only a ceiling) and only reads the `text` blocks of the reply. Don't send `temperature`/`top_p`/`top_k` or a prefilled assistant turn (the API rejects them).

## Adding WhatsApp later

WhatsApp Business API needs business verification + approved templates, so
ship Telegram first. When ready, swap the Telegram handlers for a webhook
that reads the WhatsApp Cloud API (or use Twilio's WhatsApp sandbox for
testing). `call_claude()` and the prompts stay exactly the same.
