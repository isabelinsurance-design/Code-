// Fila para listas: título + metadata izquierda, acciones derecha.
export default function ItemRow({ title, meta, badge, actions, muted = false }) {
  return (
    <div className={`flex items-start justify-between gap-3 py-2 border-b border-lino-200 last:border-0 ${muted ? 'opacity-50' : ''}`}>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 flex-wrap">
          <span className="font-medium text-ink-1 break-words">{title}</span>
          {badge && (
            <span className="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded bg-lino-200 text-lino-800">
              {badge}
            </span>
          )}
        </div>
        {meta && <div className="text-xs text-ink-3 mt-1">{meta}</div>}
      </div>
      {actions && <div className="shrink-0 flex gap-1">{actions}</div>}
    </div>
  );
}
