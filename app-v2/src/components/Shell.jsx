import { NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth.jsx';

const NAV = [
  { to: '/hoy', label: 'Hoy', icon: '☀' },
  { to: '/chat', label: 'Chat', icon: '✦' },
  { to: '/brand', label: 'Brand', icon: '◈' },
  { to: '/calendar', label: 'Agenda', icon: '◷' },
  { to: '/configura', label: 'Configura', icon: '⚙' },
  { to: '/aprueba', label: 'Aprueba', icon: '✓' },
  { to: '/tareas', label: 'Tareas', icon: '✎' },
  { to: '/wiki', label: 'Wiki', icon: '☵' },
  { to: '/actividad', label: 'Actividad', icon: '⌖' },
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
              <span className="text-base md:text-sm md:w-4">{item.icon}</span>
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
    </div>
  );
}
