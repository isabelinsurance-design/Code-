import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import Section from '../components/Section.jsx';

export default function Wiki() {
  const [wiki, setWiki] = useState([]);
  const [season, setSeason] = useState('');
  const [seasonDraft, setSeasonDraft] = useState('');
  const [seasonSaving, setSeasonSaving] = useState(false);
  const [newNote, setNewNote] = useState('');
  const [savingNote, setSavingNote] = useState(false);
  const [loading, setLoading] = useState(true);

  async function reload() {
    setLoading(true);
    try {
      const [w, s] = await Promise.all([api.wiki(), api.season()]);
      setWiki(Array.isArray(w) ? w : (w?.notas || w?.items || []));
      const txt = s?.texto || '';
      setSeason(txt);
      setSeasonDraft(txt);
    } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function saveSeason() {
    if (seasonDraft === season) return;
    setSeasonSaving(true);
    try {
      await api.seasonUpdate(seasonDraft);
      setSeason(seasonDraft);
    } finally { setSeasonSaving(false); }
  }

  async function addNote() {
    const txt = newNote.trim();
    if (!txt) return;
    setSavingNote(true);
    try {
      await api.wikiAdd(txt);
      setNewNote('');
      await reload();
    } finally { setSavingNote(false); }
  }

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Wiki y temporada</h2>
        <p className="text-ink-3 text-sm">Tu memoria persistente. Lo que Athena lee antes de cada turno.</p>
      </header>

      <Section title="Temporada actual" subtitle="En qué estás enfocada ahora. 1-2 oraciones.">
        <textarea
          rows={3}
          className="input w-full"
          value={seasonDraft}
          onChange={(e) => setSeasonDraft(e.target.value)}
          placeholder="Octubre: AEP arranca · enfoque renovaciones SCAN · YouTube 2 videos/sem."
        />
        <div className="flex justify-end gap-2">
          {seasonDraft !== season && (
            <button onClick={() => setSeasonDraft(season)} className="btn-ghost text-sm">Revertir</button>
          )}
          <button
            onClick={saveSeason}
            disabled={seasonSaving || seasonDraft === season}
            className="btn-primary text-sm"
          >
            {seasonSaving ? 'Guardando…' : 'Guardar temporada'}
          </button>
        </div>
      </Section>

      <Section title="Notas en wiki" subtitle="Hechos persistentes sobre ti. Athena las usa de contexto.">
        <div className="flex gap-2">
          <input
            className="input flex-1"
            placeholder="Una nota nueva…"
            value={newNote}
            onChange={(e) => setNewNote(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && addNote()}
          />
          <button onClick={addNote} disabled={savingNote || !newNote.trim()} className="btn-primary text-sm">
            {savingNote ? '…' : '+ Añadir'}
          </button>
        </div>
        {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
        {!loading && !wiki.length && <p className="text-ink-3 text-sm">Sin notas todavía.</p>}
        <ul className="space-y-1 mt-2">
          {wiki.slice(0, 50).map((n, i) => (
            <li key={n.id || i} className="text-sm text-ink-2 border-b border-lino-200 pb-1 last:border-0">
              {n.texto || n.nota || (typeof n === 'string' ? n : JSON.stringify(n))}
              {n.creado && <span className="text-xs text-ink-3 ml-2">{new Date(n.creado).toLocaleDateString()}</span>}
            </li>
          ))}
        </ul>
      </Section>
    </div>
  );
}
