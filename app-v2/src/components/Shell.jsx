import { NavLink, useNavigate } from 'react-router-dom';
import {
  Sun, MessageCircle, Search, Sparkles, TrendingUp, Target,
  Lightbulb, Users, ClipboardList, BookOpen, Heart, BookMarked,
  Palette, Calendar, CheckCircle2, ListChecks, FileText,
  Activity, Book, Settings,
} from 'lucide-react';
import { useAuth } from '../lib/auth.jsx';
import QuickAdd from './QuickAdd.jsx';

// Iconos de línea fina (Lucide) en lugar de emojis. Estilo quiet luxury,
// consistente con la paleta lino cálido. Stroke 1.5 para que no compita
// con el texto serif del header.
const NAV = [
  { to: '/hoy', label: 'Hoy', Icon: Sun },
  { to: '/chat', label: 'Athena', Icon: MessageCircle },
  { to: '/search', label: 'Buscar', Icon: Search },
  { to: '/coaches', label: 'Coaches', Icon: Sparkles },
  { to: '/trends', label: 'Trends', Icon: TrendingUp },
  { to: '/goals', label: 'Metas', Icon: Target },
  { to: '/insights', label: 'Insights', Icon: Lightbulb },
  { to: '/entities', label: 'Personas', Icon: Users },
  { to: '/plans', label: 'Planes', Icon: ClipboardList },
  { to: '/journal', label: 'Journal', Icon: BookOpen },
  { to: '/rapport', label: 'Rapport', Icon: Heart },
  { to: '/reading', label: 'Reading', Icon: BookMarked },
  { to: '/brand', label: 'Brand', Icon: Palette },
  { to: '/calendar', label: 'Agenda', Icon: Calendar },
  { to: '/aprueba', label: 'Aprueba', Icon: CheckCircle2 },
  { to: '/tareas', label: 'Tareas', Icon: ListChecks },
  { to: '/wiki', label: 'Wiki', Icon: FileText },
  { to: '/actividad', label: 'Actividad', Icon: Activity },
  { to: '/manual', label: 'Manual', Icon: Book },
  { to: '/configura', label: 'Configura', Icon: Settings },
];

export default function Shell({ children }) {
  const { logout } = useAuth();
  const nav = useNavigate();

  async function onLogout() {
    await logout();
    nav('/login', { replace: true });
  }

  return (
    <div className="h-full flex flex-col md:flex-row">
      {/* Sidebar desktop / bottom-nav mobile */}
      <aside className="md:w-56 bg-lino-50 border-b md:border-b-0 md:border-r border-lino-200 flex md:flex-col">
        <div className="hidden md:block px-5 py-6">
          <h1 className="font-serif text-2xl text-lino-800">Athena</h1>
          <p className="text-xs text-ink-3 mt-1">Chief of Staff</p>
        </div>
        <nav className="flex md:flex-col flex-1 overflow-x-auto md:overflow-visible">
          {NAV.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) =>
                `flex-1 md:flex-none flex flex-col md:flex-row items-center md:items-center md:gap-3 px-3 py-3 md:py-2 md:mx-2 md:my-0.5 md:rounded-lg text-xs md:text-sm transition-colors ${
                  isActive ? 'bg-lino-200 text-lino-800 md:font-medium' : 'text-ink-2 hover:bg-lino-100'
                }`
              }
            >
              <item.Icon size={18} strokeWidth={1.5} className="md:shrink-0" />
              <span>{item.label}</span>
            </NavLink>
          ))}
        </nav>
        <button onClick={onLogout} className="hidden md:block btn-ghost mx-2 mb-3 text-xs">
          Salir
        </button>
      </aside>

      {/* Contenido */}
      <main className="flex-1 overflow-y-auto">
        <div className="max-w-4xl mx-auto px-4 md:px-8 py-6 md:py-10">
          {children}
        </div>
      </main>

      {/* Quick-add FAB — disponible en TODA la app */}
      <QuickAdd />
    </div>
  );
}
