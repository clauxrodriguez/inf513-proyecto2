import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { obtenerReporte, obtenerResumenDashboard } from '../api/estadisticasApi';
import PanelEstadisticas from '../components/graficos/PanelEstadisticas';
import TarjetasResumenSla from '../components/sla/TarjetasResumenSla';
import { mensajeErrorApi, nombreEstado } from '../components/tramites/calculosTramite';
import Alerta from '../components/ui/Alerta';
import { useAuth } from '../context/AuthContext';

/** Accesos directos: las opciones del menú del rol activo (aplanadas), excepto el propio inicio. */
const accesosDirectos = (menus) => menus.flatMap((m) => (m.hijos?.length ? m.hijos : [m])).filter((m) => m.ruta && m.ruta !== '/dashboard').slice(0, 6);

export default function Dashboard() {
  const { user, roles, menus, tienePermiso } = useAuth();
  const [resumen, setResumen] = useState(null);
  const [reporte, setReporte] = useState(null);
  const [error, setError] = useState('');
  const verReportes = tienePermiso('REPORTES_VER');
  const rol = roles.find((r) => r.codigo === user?.rol)?.nombre;

  // Quien ve reportes recibe todas las estadísticas en PanelEstadisticas; el resumen simple es para los demás
  useEffect(() => {
    let vigente = true;
    if (!verReportes) {
      obtenerResumenDashboard()
        .then((res) => { if (vigente) setResumen(res.data); })
        .catch((err) => { if (vigente) setError(mensajeErrorApi(err, 'No se pudo cargar el resumen.')); });
    } else {
      obtenerReporte()
        .then((res) => { if (vigente) setReporte(res.data); })
        .catch((err) => { if (vigente) setError(mensajeErrorApi(err, 'No se pudieron cargar las estadísticas.')); });
    }
    return () => { vigente = false; };
  }, [verReportes]);

  const total = resumen?.por_estado.reduce((suma, f) => suma + Number(f.total), 0) ?? 0;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-bold text-slate-800">Bienvenido/a, {user?.nombres}</h1>
        <p className="text-sm text-slate-500">{rol} · Sistema de Legalizaciones y Firma Digital UAGRM</p>
      </header>
      <Alerta mensaje={error} />

      <section className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {accesosDirectos(menus).map((opcion) => (
          <Link key={opcion.id} to={opcion.ruta} className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4 text-sm font-medium text-slate-800 hover:border-blue-300 hover:bg-blue-50">
            {opcion.titulo}<ArrowRight className="h-4 w-4 text-blue-800" aria-hidden="true" />
          </Link>
        ))}
      </section>

      {!verReportes && resumen && (
        <section className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-3 text-lg font-semibold text-slate-800">{user?.rol === 'ESTUDIANTE' ? `Mis trámites (${total})` : `Trámites por estado (${total})`}</h2>
          {resumen.por_estado.length ? (
            <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
              {resumen.por_estado.map((f) => (
                <li key={f.estado} className="rounded-lg bg-slate-50 p-3">
                  <p className="text-xs text-slate-500">{nombreEstado(f.estado)}</p>
                  <p className="text-xl font-bold text-slate-800">{f.total}</p>
                </li>
              ))}
            </ul>
          ) : <p className="text-sm text-slate-500">Aún no hay trámites registrados.</p>}
        </section>
      )}
      {!verReportes && resumen?.sla && (
        <section className="space-y-2">
          <h2 className="text-lg font-semibold text-slate-800">Semáforo SLA (72 h hábiles)</h2>
          <TarjetasResumenSla resumen={resumen.sla} />
        </section>
      )}

      {verReportes && (reporte ? <PanelEstadisticas reporte={reporte} /> : <p className="text-sm text-slate-500">Cargando estadísticas...</p>)}
    </div>
  );
}
