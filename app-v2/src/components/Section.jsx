// Pieza reutilizable: encabezado + cuerpo en una card.
export default function Section({ title, subtitle, action, children }) {
  return (
    <section className="card">
      <header className="flex items-start justify-between gap-3 mb-4">
        <div>
          <h3 className="font-medium text-ink-1 text-lg">{title}</h3>
          {subtitle && <p className="text-sm text-ink-3 mt-0.5">{subtitle}</p>}
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </header>
      <div className="space-y-3">{children}</div>
    </section>
  );
}
