import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { ExternalLink, FileSpreadsheet } from 'lucide-react';
import { obtenerHistorialPagos } from '../api/pagosApi';
import { exportarExcel } from '../components/graficos/exportar';
import { mensajeErrorApi, moneda } from '../components/tramites/calculosTramite';
import Alerta from '../components/ui/Alerta';
import { useAuth } from '../context/AuthContext';

const ESTILO_ESTADO = {
  EXITOSO: 'bg-emerald-50 text-emerald-700',
  FALLIDO: 'bg-red-50 text-red-700',
  CANCELADO: 'bg-slate-100 text-slate-600',
  REQUIERE_ACCION: 'bg-amber-50 text-amber-700',
  PENDIENTE: 'bg-amber-50 text-amber-700',
};

/** Historial de pagos en línea: los del estudiante, o todos para la Ventanilla. */
export default function MisPagos() {
  const { user } = useAuth();
  const esEstudiante = user?.rol === 'ESTUDIANTE';
  const [pagos, setPagos] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let vigente = true;
    obtenerHistorialPagos()
      .then((res) => { if (vigente) setPagos(res.data); })
      .catch((err) => { if (vigente) setError(mensajeErrorApi(err, 'No se pudo cargar el historial de pagos.')); })
      .finally(() => { if (vigente) setCargando(false); });
    return () => { vigente = false; };
  }, []);

  const exportar = () => exportarExcel('pagos_en_linea', [
    ['fecha_pago', 'Fecha de pago'], ['tramite_codigo', 'Trámite'], ['pagador', 'Pagador'], ['monto', 'Monto'], ['moneda', 'Moneda'],
    ['estado', 'Estado'], ['marca', 'Tarjeta'], ['ultimos_cuatro', 'Últimos 4'], ['stripe_payment_intent_id', 'Referencia Stripe'],
  ], pagos);

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-800">{esEstudiante ? 'Historial de pagos' : 'Pagos en línea'}</h1>
          <p className="text-sm text-slate-500">Pagos al contado del arancel procesados con Stripe, con su comprobante digital.</p>
        </div>
        <button type="button" onClick={exportar} disabled={!pagos.length} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-white disabled:opacity-50">
          <FileSpreadsheet className="h-4 w-4" aria-hidden="true" />Exportar a Excel
        </button>
      </header>
      <Alerta mensaje={error} />
      {cargando ? <p className="text-sm text-slate-500">Cargando pagos...</p> : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-600">
              <tr>
                <th className="px-4 py-3">Fecha</th><th className="px-4 py-3">Trámite</th>{!esEstudiante && <th className="px-4 py-3">Pagador</th>}
                <th className="px-4 py-3">Monto</th><th className="px-4 py-3">Tarjeta</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3">Comprobante</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {pagos.map((p) => (
                <tr key={p.id}>
                  <td className="px-4 py-3 text-xs text-slate-600">{new Date(p.fecha_pago || p.fecha_creacion).toLocaleString('es-BO')}</td>
                  <td className="px-4 py-3"><Link to={`${esEstudiante ? '/mis-tramites' : '/tramites'}/${p.tramite_id}`} className="font-medium text-blue-900 hover:underline">{p.tramite_codigo}</Link></td>
                  {!esEstudiante && <td className="px-4 py-3">{p.pagador}</td>}
                  <td className="px-4 py-3 font-medium tabular-nums">{moneda(p.monto)}</td>
                  <td className="px-4 py-3 text-xs">{p.marca ? `${p.marca} •••• ${p.ultimos_cuatro}` : 'Tarjeta no guardada'}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${ESTILO_ESTADO[p.estado]}`}>{p.estado.replaceAll('_', ' ')}</span>
                    {p.mensaje_error && <div className="mt-1 text-xs text-slate-500">{p.mensaje_error}</div>}
                  </td>
                  <td className="px-4 py-3">
                    {p.recibo_url
                      ? <a href={p.recibo_url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs text-blue-800 hover:underline"><ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />Ver recibo</a>
                      : <span className="text-xs text-slate-400">—</span>}
                  </td>
                </tr>
              ))}
              {!pagos.length && <tr><td colSpan="7" className="px-4 py-8 text-center text-sm text-slate-500">No hay pagos registrados.</td></tr>}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
