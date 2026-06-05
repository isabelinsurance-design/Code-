import { NavLink, useNavigate } from 'react-router-dom';
import { useAuth } from '../lib/auth.jsx';
import AthenaAvatar from './AthenaAvatar.jsx';
import MissionBar from './MissionBar.jsx';
import QuickAdd from './QuickAdd.jsx';

// Sin iconos. Tipografía limpia. Estilo Hermès / Linear — el lujo
// real es el espacio en blanco y la jerarquía tipográfica. El sidebar
// agrupa por sección con un divisor sutil para no parecer lista plana.

const NAV_GROUPS = [
  {
    label: 'Diario',
    items: [
      { to: '/hoy', label: 'Hoy' },
      { to: '/chat', label: 'Athena' },
      { to: '/decisiones', label: 'Decisiones' },
      { to: '/tareas', label: 'Tareas y promesas' },
      { to: '/calendar', label: 'Agenda' },
      { to: '/aprueba', label: 'Aprueba' },
      { to: '/triage', label: 'Email triage' },
    ],
  },
  {
    label: 'Equipo',
    items: [
      { to: '/clientes', label: 'Clientes' },
      { to: '/operacion', label: 'Operación Medicare' },
      { to: '/proyectos', label: 'Proyectos' },
      { to: '/coaches', label: 'Coaches' },
      { to: '/plans', label: 'Planes' },
      { to: '/entities', label: 'Personas' },
    ],
  },
  {
    label: 'Crecimiento',
    items: [
      { to: '/goals', label: 'Metas' },
      { to: '/insights', label: 'Insights' },
      { to: '/trends', label: 'Trends' },
      { to: '/rapport', label: 'Rapport' },
      { to: '/journal', label: 'Journal' },
      { to: '/reading', label: 'Reading' },
      { to: '/brand', label: 'Brand' },
    ],
  },
  {
    label: 'Sistema',
    items: [
      { to: '/reglas', label: 'Órdenes permanentes' },
      { to: '/comandos', label: 'Cómo hablarle' },
      { to: '/wiki', label: 'Wiki y temporada' },
      { to: '/actividad', label: 'Actividad' },
      { to: '/diagnostico', label: 'Diagnóstico' },
      { to: '/uso', label: 'Uso y costos' },
      { to: '/search', label: 'Buscar' },
      { to: '/manual', label: 'Manual' },
      { to: '/configura', label: 'Configura' },
    ],
  },
];

// Para el bottom-nav mobile usamos solo los 5 más importantes.
const MOBILE_NAV = [
  { to: '/hoy', label: 'Hoy' },
  { to: '/chat', label: 'Athena' },
  { to: '/tareas', label: 'Tareas' },
  { to: '/calendar', label: 'Agenda' },
  { to: '/coaches', label: 'Coaches' },
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
      {/* Sidebar desktop */}
      <aside className="hidden md:flex md:w-64 bg-lino-50 border-r border-lino-200 md:flex-col">
        <div className="px-6 py-7 flex items-center gap-3">
          <AthenaAvatar size={48} />
          <div>
            <h1 className="font-serif text-2xl text-lino-800 leading-none">Athena</h1>
            <p className="text-xs text-ink-3 mt-1 tracking-wide">Chief of Staff</p>
          </div>
        </div>

        <nav className="flex-1 overflow-y-auto px-3 pb-4 space-y-5">
          {NAV_GROUPS.map((group) => (
            <div key={group.label}>
              <p className="px-3 mb-2 text-[10px] uppercase tracking-[0.18em] text-ink-3 font-medium">
                {group.label}
              </p>
              <div className="space-y-0.5">
                {group.items.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.to === '/hoy'}
                    className={({ isActive }) =>
                      `block px-3 py-1.5 text-sm rounded transition-colors ${
                        isActive
                          ? 'text-lino-900 font-medium bg-white/60'
                          : 'text-ink-2 hover:text-lino-800 hover:bg-white/40'
                      }`
                    }
                  >
                    {item.label}
                  </NavLink>
                ))}
              </div>
            </div>
          ))}
        </nav>

        <button onClick={onLogout} className="mx-3 mb-4 mt-2 text-xs text-ink-3 hover:text-lino-800 text-left px-3 py-1.5">
          Salir
        </button>
      </aside>

      {/* Bottom-nav mobile — 5 items principales, tipografía solo */}
      <nav className="md:hidden bg-lino-50 border-b border-lino-200 flex overflow-x-auto">
        {MOBILE_NAV.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/hoy'}
            className={({ isActive }) =>
              `flex-1 min-w-[64px] text-center py-3 text-xs transition-colors ${
                isActive
                  ? 'text-lino-900 font-medium border-b-2 border-lino-700'
                  : 'text-ink-2'
              }`
            }
          >
            {item.label}
          </NavLink>
        ))}
      </nav>

      {/* Contenido */}
      <main className="flex-1 overflow-y-auto flex flex-col">
        <MissionBar />
        <div className="flex-1 max-w-4xl mx-auto px-4 md:px-8 py-6 md:py-10 w-full">
          {children}
        </div>
      </main>

      {/* Quick-add FAB — disponible en TODA la app */}
      <QuickAdd />
    </div>
  );
}
