import { useEffect, useState } from 'react';
import { pushSupported, pushPermission, currentSubscription, enablePush, disablePush, testPush } from '../lib/push.js';
import { api } from '../lib/api.js';

export default function PushSettings() {
  const [supported, setSupported] = useState(false);
  const [perm, setPerm] = useState('default');
  const [subbed, setSubbed] = useState(false);
  const [serverReady, setServerReady] = useState(false);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState('');

  async function refresh() {
    const sup = pushSupported();
    setSupported(sup);
    setPerm(pushPermission());
    if (sup) setSubbed(!!(await currentSubscription()));
    try {
      const key = await api.pushKey();
      setServerReady(!!key.enabled);
    } catch { setServerReady(false); }
  }
  useEffect(() => { refresh(); }, []);

  async function onEnable() {
    setBusy(true); setMsg('');
    try {
      await enablePush();
      setMsg('Activado. Te van a llegar el briefing y los pings de Athena como notif nativa.');
      await refresh();
    } catch (e) { setMsg(e.message); }
    finally { setBusy(false); }
  }

  async function onDisable() {
    setBusy(true); setMsg('');
    try { await disablePush(); setMsg('Desactivado.'); await refresh(); }
    catch (e) { setMsg(e.message); }
    finally { setBusy(false); }
  }

  async function onTest() {
    setBusy(true); setMsg('');
    try {
      const r = await testPush();
      setMsg(r.sent ? `Test enviado a ${r.sent} dispositivo(s). Revisa tu iPhone.` : 'No hay dispositivos suscritos.');
    } catch (e) { setMsg(e.message); }
    finally { setBusy(false); }
  }

  if (!supported) {
    return (
      <div className="card bg-amber/5 border-amber/30">
        <p className="text-sm text-ink-2">
          <strong>Push no soportado.</strong> En iPhone, primero <em>Añadir a pantalla de inicio</em>, después abre la app desde el ícono. Safari requiere PWA instalada para permitir notificaciones.
        </p>
      </div>
    );
  }
  if (!serverReady) {
    return (
      <div className="card bg-amber/5 border-amber/30">
        <p className="text-sm text-ink-2">
          <strong>Push no configurado en el servidor.</strong> Sami necesita correr <code className="bg-lino-200 px-1 rounded">node src/push.js --generate-keys</code> y pegar <code>VAPID_PUBLIC_KEY</code> + <code>VAPID_PRIVATE_KEY</code> en Railway.
        </p>
      </div>
    );
  }
  if (perm === 'denied') {
    return (
      <div className="card bg-red/5 border-red/30">
        <p className="text-sm text-ink-2">
          <strong>Permiso bloqueado.</strong> Ve a Ajustes del iPhone → Athena → Notificaciones → permitir, y vuelve aquí.
        </p>
      </div>
    );
  }

  return (
    <div className="card">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="font-medium text-ink-1">Notificaciones push</h3>
          <p className="text-sm text-ink-3 mt-1">
            {subbed
              ? 'Activadas. El briefing y los pings de Athena llegan como notif nativa.'
              : 'Permite que Athena te pingue por notif (no solo WhatsApp).'}
          </p>
        </div>
        <div className="shrink-0 flex gap-2">
          {subbed ? (
            <>
              <button onClick={onTest} disabled={busy} className="btn-ghost text-sm">Probar</button>
              <button onClick={onDisable} disabled={busy} className="btn-ghost text-sm text-red">Desactivar</button>
            </>
          ) : (
            <button onClick={onEnable} disabled={busy} className="btn-primary text-sm">
              {busy ? '…' : 'Activar'}
            </button>
          )}
        </div>
      </div>
      {msg && <p className="text-xs text-ink-2 mt-3">{msg}</p>}
    </div>
  );
}
