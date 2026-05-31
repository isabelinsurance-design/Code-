import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import Section from '../components/Section.jsx';
import ItemRow from '../components/ItemRow.jsx';

export default function Aprueba() {
  const [skills, setSkills] = useState([]);
  const [improvements, setImprovements] = useState([]);
  const [loading, setLoading] = useState(true);

  async function reload() {
    setLoading(true);
    try {
      const [sk, im] = await Promise.all([api.skills(), api.improvements('pendiente')]);
      setSkills(sk || []);
      setImprovements(im || []);
    } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function approveSkill(slug) { await api.skillApprove(slug); await reload(); }
  async function retireSkill(slug) {
    if (!confirm('¿Retirar esta skill?')) return;
    await api.skillRetire(slug); await reload();
  }
  async function setImpStatus(id, status) {
    await api.improvementStatus(id, status); await reload();
  }

  const draftSkills = skills.filter((s) => s.status === 'draft' || s.status === 'borrador');
  const activeSkills = skills.filter((s) => s.status === 'active' || s.status === 'activa' || s.status === 'aprobada');

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Aprueba</h2>
        <p className="text-ink-3 text-sm">Lo que Athena propuso y espera tu OK.</p>
      </header>

      <Section
        title="Mejoras al código"
        subtitle="Athena detectó capacidades que le faltan. Tú decides si vale la pena construirlas."
      >
        {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
        {!loading && !improvements.length && <p className="text-ink-3 text-sm">Sin mejoras pendientes. Athena te avisará cuando proponga.</p>}
        {improvements.map((m) => {
          const dias = Math.floor((Date.now() - new Date(m.creado).getTime()) / 86400000);
          return (
            <div key={m.id} className="border-b border-lino-200 last:border-0 py-3">
              <div className="flex items-start justify-between gap-3 mb-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="font-medium text-ink-1">{m.titulo}</span>
                  <span className={`text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded ${
                    m.prioridad === 'alta' ? 'bg-red/10 text-red' :
                    m.prioridad === 'media' ? 'bg-amber/10 text-amber' :
                    'bg-lino-200 text-lino-800'
                  }`}>
                    {m.prioridad}
                  </span>
                </div>
                <div className="text-xs text-ink-3 shrink-0">{dias}d</div>
              </div>
              {m.problema && <p className="text-sm text-ink-2 mt-1"><strong>Problema:</strong> {m.problema}</p>}
              {m.propuesta && <p className="text-sm text-ink-2 mt-1"><strong>Propuesta:</strong> {m.propuesta}</p>}
              {m.github_url && (
                <p className="text-xs mt-2">
                  <a href={m.github_url} target="_blank" rel="noopener noreferrer" className="text-lino-700 hover:underline">
                    GitHub issue #{m.github_number} ↗
                  </a>
                </p>
              )}
              <div className="flex gap-2 mt-3">
                <button onClick={() => setImpStatus(m.id, 'aprobada')} className="btn-primary text-xs">Aprobar</button>
                <button onClick={() => setImpStatus(m.id, 'descartada')} className="btn-ghost text-xs">Descartar</button>
              </div>
            </div>
          );
        })}
      </Section>

      <Section title="Skills propuestas (draft)" subtitle="Playbooks que Athena armó. Apruébalas antes de que las pueda invocar.">
        {!loading && !draftSkills.length && <p className="text-ink-3 text-sm">Sin drafts.</p>}
        {draftSkills.map((s) => (
          <div key={s.nombre} className="border-b border-lino-200 last:border-0 py-3">
            <div className="font-medium text-ink-1">{s.nombre}</div>
            <p className="text-sm text-ink-2 mt-1">{s.descripcion}</p>
            {s.trigger && <p className="text-xs text-ink-3 mt-1">Trigger: {s.trigger}</p>}
            <div className="flex gap-2 mt-3">
              <button onClick={() => approveSkill(s.nombre)} className="btn-primary text-xs">Aprobar</button>
              <button onClick={() => retireSkill(s.nombre)} className="btn-ghost text-xs">Retirar</button>
            </div>
          </div>
        ))}
      </Section>

      <Section title="Skills activas" subtitle="Playbooks aprobados que Athena puede invocar sola.">
        {!loading && !activeSkills.length && <p className="text-ink-3 text-sm">Ninguna activa todavía.</p>}
        {activeSkills.map((s) => (
          <ItemRow
            key={s.nombre}
            title={s.nombre}
            meta={s.descripcion}
            actions={
              <button onClick={() => retireSkill(s.nombre)} className="text-xs text-red hover:underline px-2">
                Retirar
              </button>
            }
          />
        ))}
      </Section>
    </div>
  );
}
