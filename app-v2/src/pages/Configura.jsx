import { useState } from 'react';
import RoutinesPanel from '../components/configura/RoutinesPanel.jsx';
import FocusPanel from '../components/configura/FocusPanel.jsx';
import ResearchPanel from '../components/configura/ResearchPanel.jsx';
import CoachesPanel from '../components/configura/CoachesPanel.jsx';

const TABS = [
  { id: 'coaches', label: 'Coaches' },
  { id: 'rutinas', label: 'Rutinas' },
  { id: 'focus', label: 'Focus blocks' },
  { id: 'research', label: 'Research' },
];

export default function Configura() {
  const [tab, setTab] = useState('coaches');

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Configura</h2>
        <p className="text-ink-3 text-sm">Lo que Athena sabe de ti — editable.</p>
      </header>

      {/* Tabs */}
      <div className="flex gap-1 border-b border-lino-200 -mx-2 px-2 overflow-x-auto">
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors ${
              tab === t.id
                ? 'border-lino-600 text-lino-800'
                : 'border-transparent text-ink-3 hover:text-ink-2'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'coaches' && <CoachesPanel />}
      {tab === 'rutinas' && <RoutinesPanel />}
      {tab === 'focus' && <FocusPanel />}
      {tab === 'research' && <ResearchPanel />}
    </div>
  );
}
