import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth.jsx';

export default function Login() {
  const { login } = useAuth();
  const nav = useNavigate();
  const [password, setPassword] = useState('');
  const [err, setErr] = useState('');
  const [loading, setLoading] = useState(false);

  async function onSubmit(e) {
    e.preventDefault();
    setErr('');
    setLoading(true);
    try {
      await login(password);
      nav('/hoy', { replace: true });
    } catch (e2) {
      setErr('Password incorrecto.');
      setLoading(false);
    }
  }

  return (
    <div className="h-full grid place-items-center px-6">
      <div className="w-full max-w-sm">
        <h1 className="font-serif text-4xl text-lino-800 mb-2 text-center">Athena</h1>
        <p className="text-ink-3 text-sm text-center mb-8">Tu Chief of Staff.</p>
        <form onSubmit={onSubmit} className="card space-y-4">
          <div>
            <label className="label">Password</label>
            <input
              type="password"
              autoFocus
              autoComplete="current-password"
              className="input w-full"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </div>
          {err && <p className="text-red text-sm">{err}</p>}
          <button type="submit" disabled={loading || !password} className="btn-primary w-full">
            {loading ? 'Entrando…' : 'Entrar'}
          </button>
        </form>
      </div>
    </div>
  );
}
