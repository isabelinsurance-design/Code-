"""Pruebas del bot sin red ni Telegram real:  python bot/test_bot.py"""
import asyncio
import os
import sys
import types
import unittest
from datetime import datetime

os.environ["ALLOWED_CHAT_IDS"] = "111, 222"
os.environ["ANTHROPIC_API_KEY"] = "sk-ant-test"
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

try:
    import telegram  # noqa: F401
    import telegram.ext  # noqa: F401
except BaseException:  # pragma: no cover — entorno sin la librería (o rota): usar un doble mínimo
    tg = types.ModuleType("telegram"); tg.Update = type("Update", (), {"ALL_TYPES": []})
    const = types.ModuleType("telegram.constants"); const.ChatAction = type("ChatAction", (), {"TYPING": "typing"})
    ext = types.ModuleType("telegram.ext")
    for n in ("Application", "CommandHandler", "ContextTypes", "MessageHandler"):
        setattr(ext, n, type(n, (), {"DEFAULT_TYPE": object}))
    ext.filters = types.SimpleNamespace(TEXT=types.SimpleNamespace(__and__=lambda s, o: s, __rand__=lambda s, o: s), COMMAND=None)
    sys.modules.update({"telegram": tg, "telegram.constants": const, "telegram.ext": ext})

import anthropic  # noqa: E402
import bot  # noqa: E402


class FakeMsg:
    def __init__(self, text="hola"):
        self.text, self.sent = text, []
        self.chat = types.SimpleNamespace(send_action=self._act)

    async def _act(self, _):
        pass

    async def reply_text(self, t, **kw):
        self.sent.append(t)


def update(chat_id, text="hola"):
    return types.SimpleNamespace(effective_chat=types.SimpleNamespace(id=chat_id), message=FakeMsg(text))


def ctx(*args):
    return types.SimpleNamespace(args=list(args))


class FakeClient:
    def __init__(self, reply="Respuesta", blocks=None, stop="end_turn", exc=None):
        self.calls, self.exc = [], exc
        self.blocks = blocks or [types.SimpleNamespace(type="thinking"), types.SimpleNamespace(type="text", text=reply)]
        self.stop = stop
        self.messages = types.SimpleNamespace(create=self._create)

    async def _create(self, **kw):
        self.calls.append(kw)
        await asyncio.sleep(0.05)
        if self.exc:
            raise self.exc
        return types.SimpleNamespace(content=self.blocks, stop_reason=self.stop)


def run(coro):
    return asyncio.run(coro)


class BotTests(unittest.TestCase):
    def setUp(self):
        bot._client = FakeClient()

    def test_allowed_chat_gets_an_answer_and_thinking_blocks_are_ignored(self):
        u = update(111, "dame ideas")
        run(bot.on_text(u, ctx()))
        self.assertEqual(u.message.sent, ["Respuesta"])

    def test_request_uses_current_model_effort_and_room_for_thinking(self):
        run(bot.on_text(update(111), ctx()))
        kw = bot._client.calls[0]
        self.assertEqual(kw["model"], "claude-sonnet-5-5")
        self.assertEqual(kw["extra_body"], {"output_config": {"effort": "medium"}})
        self.assertGreaterEqual(kw["max_tokens"], 4000)
        for forbidden in ("temperature", "top_p", "top_k"):
            self.assertNotIn(forbidden, kw)

    def test_system_prompt_has_the_2027_rules_and_today(self):
        run(bot.on_text(update(111), ctx()))
        s = bot._client.calls[0]["system"]
        self.assertIn("REGLAS CMS PARA ESTE AEP", s)
        self.assertIn("NO exige esperar 48 horas", s)
        self.assertIn("CONTEXTO DE HOY", s)
        self.assertIn("Telegram", s)

    def test_unknown_chat_is_ignored_and_costs_nothing(self):
        u = update(999, "hola")
        run(bot.on_text(u, ctx()))
        self.assertEqual(u.message.sent, [])
        self.assertEqual(bot._client.calls, [])
        run(bot.make_quick_handler("idea", False)(update(999), ctx()))
        run(bot.cmd_semana(update(999), ctx()))
        self.assertEqual(bot._client.calls, [])

    def test_start_shows_the_chat_id_to_strangers_and_the_menu_to_isabel(self):
        stranger = update(999)
        run(bot.cmd_start(stranger, ctx()))
        self.assertIn("999", stranger.message.sent[0])
        self.assertEqual(bot._client.calls, [])
        isabel = update(111)
        run(bot.cmd_start(isabel, ctx()))
        self.assertIn("/semana", isabel.message.sent[0])

    def test_long_answers_are_split_under_telegram_limit(self):
        bot._client = FakeClient("párrafo " * 20 + ("\n\n" + "x" * 3000) * 4)
        u = update(111)
        run(bot.on_text(u, ctx()))
        self.assertGreater(len(u.message.sent), 1)
        self.assertTrue(all(len(p) <= 4096 for p in u.message.sent))

    def test_semana_runs_six_requests_together(self):
        u = update(222)
        run(bot.cmd_semana(u, ctx()))
        self.assertEqual(len(bot._client.calls), 6)
        self.assertTrue(all(c["max_tokens"] >= 4000 for c in bot._client.calls))
        self.assertTrue(any("Ganchos" in m for m in u.message.sent))
        self.assertIn("✅ Listo", u.message.sent[-1])

    def test_quick_command_uses_the_topic(self):
        run(bot.make_quick_handler("hook", True)(update(111), ctx("dental", "y", "visión")))
        self.assertIn("dental y visión", bot._client.calls[0]["messages"][0]["content"])

    def test_friendly_errors(self):
        req = types.SimpleNamespace(method="POST", url="https://api.anthropic.com/v1/messages")
        def resp(code): return types.SimpleNamespace(status_code=code, request=req, headers={}, text="")
        self.assertIn("llave", bot.friendly_error(anthropic.AuthenticationError("x", response=resp(401), body=None)))
        self.assertIn("modelo", bot.friendly_error(anthropic.NotFoundError("x", response=resp(404), body=None)))
        self.assertIn("saldo", bot.friendly_error(Exception("Your credit balance is too low")))
        bot._client = FakeClient(exc=anthropic.RateLimitError("x", response=resp(429), body=None))
        u = update(111)
        run(bot.on_text(u, ctx()))
        self.assertIn("demasiadas", u.message.sent[0])

    def test_refusal_is_explained(self):
        bot._client = FakeClient(stop="refusal", blocks=[])
        u = update(111)
        run(bot.on_text(u, ctx()))
        self.assertIn("No pude responder", u.message.sent[0])

    def test_context_counts_days_to_aep(self):
        la = bot.LA
        self.assertIn("abre en 5 días", bot.context_block(datetime(2026, 10, 10, 9, tzinfo=la)))
        self.assertIn("día 6 de 54", bot.context_block(datetime(2026, 10, 20, 9, tzinfo=la)))
        self.assertIn("ya cerró", bot.context_block(datetime(2026, 12, 10, 9, tzinfo=la)))

    def test_allowed_ids_parsing(self):
        self.assertEqual(bot.parse_ids(" 1, -2 ,x,3"), {1, -2, 3})
        self.assertEqual(bot.parse_ids(""), set())


if __name__ == "__main__":
    unittest.main(verbosity=2)
