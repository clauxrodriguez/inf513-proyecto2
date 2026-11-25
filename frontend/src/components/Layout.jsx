import { useState } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import * as Icons from 'lucide-react';
import { useAuth } from '../context/AuthContext';
import Notificaciones from './Notificaciones';
import ContadorVisitas from './ContadorVisitas';
import BuscadorGlobal from './BuscadorGlobal';
import PanelAccesibilidad from './PanelAccesibilidad';

/** Busca la opción del menú (en cualquier nivel) que corresponde a la ruta actual. */
const buscarOpcion = (opciones, ruta) => {
  for (const opcion of opciones) {
    if (opcion.ruta === ruta) return opcion;
    const hija = buscarOpcion(opcion.hijos ?? [], ruta);
    if (hija) return hija;
  }
  return null;
};

function EnlaceMenu({ opcion, activo, nivel = 0 }) {
  const Icono = Icons[opcion.icono] || Icons.Circle;
  return (
    <Link
      to={opcion.ruta}
      aria-current={activo ? 'page' : undefined}
      className={`flex items-center gap-3 rounded-lg py-2.5 text-sm transition ${nivel ? 'pl-9 pr-3' : 'px-3'} ${
        activo ? 'bg-blue-800 font-medium text-white' : 'text-blue-200 hover:bg-blue-900 hover:text-white'
      }`}
    >
      <Icono className="h-4 w-4 shrink-0" aria-hidden="true" />
      <span>{opcion.titulo}</span>
    </Link>
  );
}

/** Opción con hijas (padre_id en menu_opcion): se despliega y queda abierta si contiene la página actual. */
function GrupoMenu({ opcion, rutaActual }) {
  const contieneActual = Boolean(buscarOpcion(opcion.hijos, rutaActual));
  const [abierto, setAbierto] = useState(contieneActual);
  const Icono = Icons[opcion.icono] || Icons.Folder;
  const visible = abierto || contieneActual;

  return (
    <div>
      <button
        type="button"
        onClick={() => setAbierto(!visible)}
        aria-expanded={visible}
        className="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-blue-200 transition hover:bg-blue-900 hover:text-white"
      >
        <Icono className="h-4 w-4 shrink-0" aria-hidden="true" />
        <span className="flex-1 text-left">{opcion.titulo}</span>
        <Icons.ChevronDown className={`h-4 w-4 transition ${visible ? 'rotate-180' : ''}`} aria-hidden="true" />
      </button>
      {visible && (
        <div className="mt-1 space-y-1">
          {opcion.hijos.map((hija) => (
            <EnlaceMenu key={hija.id} opcion={hija} activo={hija.ruta === rutaActual} nivel={1} />
          ))}
        </div>
      )}
    </div>
  );
}

export default function Layout() {
  const { user, roles, menus, logout, cambiarRol } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [cambiandoRol, setCambiandoRol] = useState(false);

  const opcionActual = buscarOpcion(menus, location.pathname);
  const rolActual = roles.find((r) => r.codigo === user?.rol);

  const handleLogout = async () => {
    await logout();
    navigate('/login');
  };

  const handleCambioRol = async (e) => {
    setCambiandoRol(true);
    try {
      const datos = await cambiarRol(e.target.value);
      navigate(datos.menus?.[0]?.ruta || datos.menus?.[0]?.hijos?.[0]?.ruta || '/dashboard');
    } finally {
      setCambiandoRol(false);
    }
  };

  return (
    <div className="h-screen flex overflow-hidden bg-slate-50">
      {/* Menú dinámico: menu_opcion filtrado por los permisos del rol activo */}
      <aside className="w-64 h-screen shrink-0 bg-blue-950 text-white flex flex-col">
        <div className="p-5 border-b border-blue-900">
          <p className="text-xs uppercase tracking-wider text-blue-300">UAGRM — INF513</p>
          <h2 className="font-bold text-lg leading-tight mt-1">Legalizaciones</h2>
          {roles.length > 1 ? (
            <label className="mt-3 block text-xs text-blue-300">
              Rol activo
              <select
                value={user?.rol}
                onChange={handleCambioRol}
                disabled={cambiandoRol}
                className="mt-1 w-full rounded bg-blue-900 px-2 py-1 text-sm text-white"
              >
                {roles.map((r) => <option key={r.codigo} value={r.codigo}>{r.nombre}</option>)}
              </select>
            </label>
          ) : (
            <span className="inline-block mt-2 text-xs bg-blue-800 px-2 py-0.5 rounded">{rolActual?.nombre || user?.rol}</span>
          )}
        </div>

        <nav aria-label="Menú principal" className="flex-1 min-h-0 overflow-y-auto p-3 space-y-1">
          {menus.map((opcion) => (opcion.hijos?.length
            ? <GrupoMenu key={opcion.id} opcion={opcion} rutaActual={location.pathname} />
            : <EnlaceMenu key={opcion.id} opcion={opcion} activo={opcion.ruta === location.pathname} />))}
        </nav>

        <div className="p-4 border-t border-blue-900">
          <Link to="/perfil" className="block text-sm font-medium truncate hover:underline">
            {user?.nombres} {user?.apellidos}
          </Link>
          <p className="text-xs text-blue-300 truncate mb-3">{user?.email}</p>
          <button
            onClick={handleLogout}
            className="w-full flex items-center justify-center gap-2 bg-red-600/90 hover:bg-red-600 text-white text-sm py-2 rounded-lg transition"
          >
            <Icons.LogOut className="w-4 h-4" aria-hidden="true" />
            Cerrar Sesión
          </button>
        </div>
      </aside>

      <div className="flex-1 min-w-0 min-h-0 flex flex-col">
        <header className="flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-6 py-2">
          <span className="hidden shrink-0 text-sm font-medium text-slate-600 lg:block">{opcionActual?.titulo || 'Sistema de Legalizaciones'}</span>
          <BuscadorGlobal />
          <div className="flex shrink-0 items-center gap-1">
            <PanelAccesibilidad />
            <Notificaciones />
          </div>
        </header>
        <main className="flex-1 min-h-0 overflow-y-auto p-6">
          <Outlet />
        </main>

        <footer className="bg-white border-t border-slate-200 px-6 py-3 flex justify-between items-center text-xs text-slate-500">
          <span>Vicerrectorado UAGRM — Sistema de Legalizaciones con Firma Digital</span>
          <ContadorVisitas titulo={opcionActual?.titulo} />
        </footer>
      </div>
    </div>
  );
}
