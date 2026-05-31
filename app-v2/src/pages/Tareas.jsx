import { useState } from 'react';
import TasksPanel from '../components/tareas/TasksPanel.jsx';
import CommitmentsPanel from '../components/tareas/CommitmentsPanel.jsx';

const TABS = [
  { id: 'tareas', label: 'Tareas' },
  { id: 'compromisos', label: 'Te deben' },
];

export default function Tareas() {
  const [tab, setTab] = useState('tareas');

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Tareas & promesas</h2>
        <p className="text-ink-3 text-sm">Lo que tú debes vs lo que te deben.</p>
      </header>

      <div className="flex gap-1 border-b border-lino-200 -mx-2 px-2 overflow-x-auto">
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors ${
              tab === t.id ? 'border-lino-600 text-lino-800' : 'border-transparent text-ink-3 hover:text-ink-2'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'tareas' && <TasksPanel />}
      {tab === 'compromisos' && <CommitmentsPanel />}
    </div>
  );
}
