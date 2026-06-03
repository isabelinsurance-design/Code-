import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import MarkdownView from '../components/MarkdownView.jsx';

const DOCS = [
  { id: 'manual', label: 'Manual de Athena', desc: 'qué hace, cuándo, cómo, por qué' },
  { id: 'sami', label: 'Runbook de Sami', desc: 'setup + SOPs + troubleshooting' },
  { id: 'pendientes', label: 'Pendientes', desc: 'lista para próxima sesión' },
];

export default function Manual() {
  const [tab, setTab] = useState('manual');
  const [content, setContent] = useState('');
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');

  useEffect(() => {
    setLoading(true);
    setErr('');
    api.doc(tab)
      .then((r) => setContent(r.content || ''))
      .catch((e) => setErr(e.message))
      .finally(() => setLoading(false));
  }, [tab]);

  const active = DOCS.find((d) => d.id === tab);

  return (
    <div className="space-y-4">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Manual</h2>
        <p className="text-ink-3 text-sm">Documentos vivos del repo. Source of truth: archivos .md en la raíz.</p>
      </header>

      <div className="flex gap-2 flex-wrap">
        {DOCS.map((d) => (
          <button
            key={d.id}
            onClick={() => setTab(d.id)}
            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
              tab === d.id ? 'bg-lino-700 text-white' : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
            }`}
            title={d.desc}
          >
            {d.label}
          </button>
        ))}
      </div>

      {err && <p className="text-red text-xs">{err}</p>}
      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}

      {!loading && content && (
        <article className="card">
          <p className="text-xs text-ink-3 mb-3 italic">{active?.desc}</p>
          <MarkdownView content={content} />
        </article>
      )}
    </div>
  );
}
