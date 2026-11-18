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
import Bitacora from './pages/Bitacora';
import Despacho from './pages/Despacho';
import Rectorado from './pages/Rectorado';
import Certificados from './pages/Certificados';
import MonitoreoSla from './pages/MonitoreoSla';
import Seguimiento from './pages/publico/Seguimiento';
import ValidarCertificado from './pages/publico/ValidarCertificado';

function RutaProtegida({ children }) {
  const { user, loading } = useAuth();
  if (loading) return <div className="p-8 text-center text-slate-600">Cargando sesión...</div>;
  return user ? children : <Navigate to="/login" replace />;
}

function VistaPlaceholder({ titulo }) {
  return (
    <div className="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
      <h1 className="text-xl font-bold text-slate-800">{titulo}</h1>
      <p className="text-sm text-slate-500 mt-1">
        Módulo conectado al menú dinámico de PostgreSQL.
      </p>
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
            <Route index element={<Navigate to="/dashboard" replace />} />
            <Route path="dashboard" element={<VistaPlaceholder titulo="Dashboard Ejecutivo" />} />
            <Route path="usuarios" element={<Usuarios />} />
            <Route path="perfil" element={<Perfil />} />
            <Route path="delegaciones" element={<Delegaciones />} />
            <Route path="tramites" element={<ListaTramites />} />
            <Route path="tramites/:tramiteId" element={<DetalleTramite />} />
            <Route path="tramites/nuevo" element={<NuevoTramite />} />
            <Route path="despacho" element={<Despacho />} />
            <Route path="certificados" element={<Certificados />} />
            <Route path="monitoreo-sla" element={<MonitoreoSla />} />
            <Route path="reportes" element={<VistaPlaceholder titulo="Reportes e Indicadores (CU8)" />} />
            <Route path="bitacora" element={<Bitacora />} />
            <Route path="catalogos" element={<VistaPlaceholder titulo="Catálogo de Documentos y Requisitos" />} />
            <Route path="rectorado" element={<Rectorado />} />
            <Route path="mis-tramites" element={<ListaTramites />} />
            <Route path="mis-tramites/:tramiteId" element={<DetalleTramite />} />
            <Route path="solicitar" element={<NuevoTramite />} />
            <Route path="guia" element={<GuiaProcesal />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}