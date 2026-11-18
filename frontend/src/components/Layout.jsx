import { useEffect, useState } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import * as Icons from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import { registrarVisita } from '../api/authApi';
import Notificaciones from './Notificaciones';

export default function Layout() {
  const { user, menus, logout } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [visitas, setVisitas] = useState(0);

  const menuActual = menus.find((m) => m.ruta === location.pathname);
  const accesosCU4 = [
    { id: 'cu4-guia', titulo: 'Guía Procesal', ruta: '/guia', icono: 'BookOpen' },
    ...(user?.rol !== 'ESTUDIANTE'
      ? [{ id: 'cu4-revision', titulo: 'Revisión de Trámites', ruta: '/tramites', icono: 'FolderCheck' }]
      : []),
  ].filter((acceso) => !menus.some((item) => item.ruta === acceso.ruta));
  const menuVisible = [...menus, ...accesosCU4];

  useEffect(() => {
    const titulo = menuActual?.titulo || document.title || location.pathname;
    registrarVisita(location.pathname, titulo)
      .then((res) => setVisitas(res.data.visitas))
      .catch(() => {});
  }, [location.pathname, menuActual]);

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  return (
    <div className="h-screen flex overflow-hidden bg-slate-50">
      {/* Sidebar Dinámico desde PostgreSQL */}
      <aside className="w-64 h-screen shrink-0 bg-blue-950 text-white flex flex-col">
        <div className="p-5 border-b border-blue-900">
          <p className="text-xs uppercase tracking-wider text-blue-300">UAGRM — INF513</p>
          <h2 className="font-bold text-lg leading-tight mt-1">Legalizaciones</h2>
          <span className="inline-block mt-2 text-xs bg-blue-800 px-2 py-0.5 rounded">
            {user?.rol}
          </span>
        </div>

        <nav className="flex-1 min-h-0 overflow-y-auto p-3 space-y-1">
          {menuVisible.map((item) => {
            const IconComponent = Icons[item.icono] || Icons.Circle;
            const activo = location.pathname === item.ruta;
            return (
              <Link
                key={item.id}
                to={item.ruta}
                className={`flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition ${
                  activo
                    ? 'bg-blue-800 text-white font-medium'
                    : 'text-blue-200 hover:bg-blue-900 hover:text-white'
                }`}
              >
                <IconComponent className="w-4 h-4 shrink-0" />
                <span>{item.titulo}</span>
              </Link>
            );
          })}
        </nav>

        <div className="p-4 border-t border-blue-900">
          <p className="text-sm font-medium truncate">
            {user?.nombres} {user?.apellidos}
          </p>
          <p className="text-xs text-blue-300 truncate mb-3">{user?.email}</p>
          <button
            onClick={handleLogout}
            className="w-full flex items-center justify-center gap-2 bg-red-600/90 hover:bg-red-600 text-white text-sm py-2 rounded-lg transition"
          >
            <Icons.LogOut className="w-4 h-4" />
            Cerrar Sesión
          </button>
        </div>
      </aside>

      {/* Área Principal + Footer con Contador */}
      <div className="flex-1 min-w-0 min-h-0 flex flex-col">
        <header className="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-2">
          <span className="text-sm font-medium text-slate-600">{menuActual?.titulo || 'Sistema de Legalizaciones'}</span>
          <Notificaciones />
        </header>
        <main className="flex-1 min-h-0 overflow-y-auto p-6">
          <Outlet />
        </main>

        <footer className="bg-white border-t border-slate-200 px-6 py-3 flex justify-between items-center text-xs text-slate-500">
          <span>Vicerrectorado UAGRM — Sistema de Legalizaciones con Firma Digital</span>
          <span className="font-medium text-slate-700 bg-slate-100 px-3 py-1 rounded-full">
            Visitas a esta página: {visitas}
          </span>
        </footer>
      </div>
    </div>
  );
}