import { Routes, Route, Navigate, Outlet } from 'react-router-dom';
import { AuthProvider, useAuth } from './lib/auth.jsx';
import Login from './pages/Login.jsx';
import Shell from './components/Shell.jsx';
import Hoy from './pages/Hoy.jsx';
import Chat from './pages/Chat.jsx';
import Configura from './pages/Configura.jsx';
import Aprueba from './pages/Aprueba.jsx';
import Brand from './pages/Brand.jsx';
import Calendar from './pages/Calendar.jsx';
import Tareas from './pages/Tareas.jsx';
import Wiki from './pages/Wiki.jsx';
import Actividad from './pages/Actividad.jsx';
import Journal from './pages/Journal.jsx';
import Reading from './pages/Reading.jsx';
import Rapport from './pages/Rapport.jsx';
import Plans from './pages/Plans.jsx';
import Search from './pages/Search.jsx';
import Coaches from './pages/Coaches.jsx';
import Trends from './pages/Trends.jsx';
import Goals from './pages/Goals.jsx';
import Insights from './pages/Insights.jsx';
import Entities from './pages/Entities.jsx';
import Manual from './pages/Manual.jsx';
import Comandos from './pages/Comandos.jsx';
import Decisiones from './pages/Decisiones.jsx';
import Proyectos from './pages/Proyectos.jsx';
import ProyectoDetalle from './pages/ProyectoDetalle.jsx';
import Reglas from './pages/Reglas.jsx';
import Clientes from './pages/Clientes.jsx';
import ClienteExpediente from './pages/ClienteExpediente.jsx';
import Diagnostico from './pages/Diagnostico.jsx';
import Uso from './pages/Uso.jsx';
import Triage from './pages/Triage.jsx';

function Protected() {
  const { user } = useAuth();
  if (user === undefined) {
    return (
      <div className="h-full grid place-items-center text-ink-3">
        <span>Cargando…</span>
      </div>
    );
  }
  if (!user) return <Navigate to="/login" replace />;
  return (
    <Shell>
      <Outlet />
    </Shell>
  );
}

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route element={<Protected />}>
          <Route path="/" element={<Navigate to="/hoy" replace />} />
          <Route path="/hoy" element={<Hoy />} />
          <Route path="/chat" element={<Chat />} />
          <Route path="/chat/:coach" element={<Chat />} />
          <Route path="/configura" element={<Configura />} />
          <Route path="/aprueba" element={<Aprueba />} />
          <Route path="/brand" element={<Brand />} />
          <Route path="/calendar" element={<Calendar />} />
          <Route path="/tareas" element={<Tareas />} />
          <Route path="/wiki" element={<Wiki />} />
          <Route path="/journal" element={<Journal />} />
          <Route path="/reading" element={<Reading />} />
          <Route path="/rapport" element={<Rapport />} />
          <Route path="/plans" element={<Plans />} />
          <Route path="/search" element={<Search />} />
          <Route path="/coaches" element={<Coaches />} />
          <Route path="/trends" element={<Trends />} />
          <Route path="/goals" element={<Goals />} />
          <Route path="/insights" element={<Insights />} />
          <Route path="/entities" element={<Entities />} />
          <Route path="/manual" element={<Manual />} />
          <Route path="/comandos" element={<Comandos />} />
          <Route path="/decisiones" element={<Decisiones />} />
          <Route path="/proyectos" element={<Proyectos />} />
          <Route path="/proyectos/:id" element={<ProyectoDetalle />} />
          <Route path="/reglas" element={<Reglas />} />
          <Route path="/clientes" element={<Clientes />} />
          <Route path="/clientes/:id" element={<ClienteExpediente />} />
          <Route path="/diagnostico" element={<Diagnostico />} />
          <Route path="/uso" element={<Uso />} />
          <Route path="/triage" element={<Triage />} />
          <Route path="/actividad" element={<Actividad />} />
        </Route>
        <Route path="*" element={<Navigate to="/hoy" replace />} />
      </Routes>
    </AuthProvider>
  );
}
