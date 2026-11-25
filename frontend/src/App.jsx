import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import Login from './pages/Login';
import Layout from './components/Layout';
import Usuarios from './pages/Usuarios';
import Perfil from './pages/Perfil';
import Delegaciones from './pages/Delegaciones';
import NuevoTramite from './pages/NuevoTramite';
import ListaTramites from './pages/ListaTramites';
import DetalleTramite from './pages/DetalleTramite';
import GuiaProcesal from './pages/GuiaProcesal';
import BandejaOrigen from './pages/BandejaOrigen';
import Bitacora from './pages/Bitacora';
import Despacho from './pages/Despacho';
import Rectorado from './pages/Rectorado';
import Certificados from './pages/Certificados';
import MonitoreoSla from './pages/MonitoreoSla';
import Dashboard from './pages/Dashboard';
import Reportes from './pages/Reportes';
import MisMetodosPago from './pages/MisMetodosPago';
import MisPagos from './pages/MisPagos';
import Seguimiento from './pages/publico/Seguimiento';
import ValidarCertificado from './pages/publico/ValidarCertificado';

function RutaProtegida({ children }) {
  const { user, loading } = useAuth();
  if (loading) return <div className="p-8 text-center text-slate-600">Cargando sesión...</div>;
  return user ? children : <Navigate to="/login" replace />;
}

/** ¿La ruta está en el menú (cualquier nivel) del rol activo? */
const rutaEnMenu = (menus, ruta) => menus.some((m) => m.ruta === ruta || rutaEnMenu(m.hijos ?? [], ruta));

/**
 * Matriz de acceso en el cliente: una sección solo se muestra si el menú del rol activo la incluye
 * (la API vuelve a verificar el permiso en cada petición).
 */
function Permitida({ ruta, children }) {
  const { menus } = useAuth();
  if (rutaEnMenu(menus, ruta)) return children;
  return (
    <div role="alert" className="rounded-xl border border-amber-200 bg-amber-50 p-6">
      <h1 className="text-lg font-bold text-amber-900">Acceso denegado</h1>
      <p className="mt-1 text-sm text-amber-800">Su rol activo no tiene permiso para esta sección. Si tiene otro rol, cámbielo desde el menú lateral.</p>
    </div>
  );
}

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter basename={import.meta.env.BASE_URL}>
        <Routes>
          <Route path="/login" element={<Login />} />
          {/* CU6: páginas públicas (sin sesión) */}
          <Route path="/seguimiento" element={<Seguimiento />} />
          <Route path="/validar-certificado" element={<ValidarCertificado />} />

          <Route
            path="/"
            element={
              <RutaProtegida>
                <Layout />
              </RutaProtegida>
            }
          >
            {/* Las rutas coinciden con menu_opcion.ruta (AccesoSeeder); el detalle no está en el menú */}
            <Route index element={<Navigate to="/dashboard" replace />} />
            <Route path="dashboard" element={<Permitida ruta="/dashboard"><Dashboard /></Permitida>} />
            <Route path="perfil" element={<Perfil />} />
            <Route path="mis-tramites" element={<Permitida ruta="/mis-tramites"><ListaTramites /></Permitida>} />
            <Route path="mis-tramites/nuevo" element={<Permitida ruta="/mis-tramites/nuevo"><NuevoTramite /></Permitida>} />
            <Route path="mis-tramites/:tramiteId" element={<DetalleTramite />} />
            <Route path="mis-metodos-pago" element={<Permitida ruta="/mis-metodos-pago"><MisMetodosPago /></Permitida>} />
            <Route path="mis-pagos" element={<Permitida ruta="/mis-pagos"><MisPagos /></Permitida>} />
            <Route path="recepcion/pagos" element={<Permitida ruta="/recepcion/pagos"><MisPagos /></Permitida>} />
            <Route path="bandeja-origen" element={<Permitida ruta="/bandeja-origen"><BandejaOrigen /></Permitida>} />
            <Route path="recepcion" element={<Permitida ruta="/recepcion"><ListaTramites /></Permitida>} />
            <Route path="tramites/:tramiteId" element={<DetalleTramite />} />
            <Route path="certificados" element={<Permitida ruta="/certificados"><Certificados /></Permitida>} />
            <Route path="despacho-firma" element={<Permitida ruta="/despacho-firma"><Despacho /></Permitida>} />
            <Route path="rectorado" element={<Permitida ruta="/rectorado"><Rectorado /></Permitida>} />
            <Route path="delegaciones" element={<Permitida ruta="/delegaciones"><Delegaciones /></Permitida>} />
            <Route path="monitor-sla" element={<Permitida ruta="/monitor-sla"><MonitoreoSla /></Permitida>} />
            <Route path="reportes" element={<Permitida ruta="/reportes"><Reportes /></Permitida>} />
            <Route path="guia" element={<Permitida ruta="/guia"><GuiaProcesal /></Permitida>} />
            <Route path="admin/usuarios" element={<Permitida ruta="/admin/usuarios"><Usuarios /></Permitida>} />
            <Route path="admin/bitacora" element={<Permitida ruta="/admin/bitacora"><Bitacora /></Permitida>} />
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}