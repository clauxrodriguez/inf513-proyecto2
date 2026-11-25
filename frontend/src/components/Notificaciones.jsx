import { useCallback, useEffect, useRef, useState } from 'react';
import { Bell, CheckCheck } from 'lucide-react';
import { marcarNotificacionLeida, marcarTodasLeidas, obtenerNotificaciones } from '../api/notificacionesApi';

const ETIQUETA = {
  PENDIENTE_REVISION: 'Revisión',
  PENDIENTE_FIRMA: 'Firma',
  PENDIENTE_RECTORADO: 'Rectorado',
  CAMBIO_ESTADO: 'Estado',
  ALERTA_SLA: 'Alerta SLA',
  LISTO_CERTIFICADO: 'Certificado',
  CERTIFICADO_ANULADO: 'Anulación',
  CERTIFICADO_REEMITIDO: 'Certificado',
};
const INTERVALO_MS = 60_000;

/** RF-19: campana con las notificaciones que generan los triggers (firma pendiente, alertas SLA, cambios de estado...). */
export default function Notificaciones() {
  const [datos, setDatos] = useState({ no_leidas: 0, notificaciones: [] });
  const [abierto, setAbierto] = useState(false);
  const contenedor = useRef(null);

  const cargar = useCallback(() => {
    obtenerNotificaciones().then((res) => setDatos(res.data)).catch(() => {});
  }, []);

  useEffect(() => {
    cargar();
    const intervalo = setInterval(cargar, INTERVALO_MS);
    return () => clearInterval(intervalo);
  }, [cargar]);

  useEffect(() => {
    if (!abierto) return undefined;
    const cerrar = (event) => { if (!contenedor.current?.contains(event.target)) setAbierto(false); };
    document.addEventListener('mousedown', cerrar);
    return () => document.removeEventListener('mousedown', cerrar);
  }, [abierto]);

  const leer = async (notificacion) => {
    if (notificacion.leida) return;
    await marcarNotificacionLeida(notificacion.id).catch(() => {});
    cargar();
  };
  const leerTodas = async () => {
    await marcarTodasLeidas().catch(() => {});
    cargar();
  };

  return (
    <div ref={contenedor} className="relative">
      <button type="button" onClick={() => setAbierto((v) => !v)} aria-label="Notificaciones"
        className="relative rounded-lg p-2 text-slate-600 hover:bg-slate-100">
        <Bell className="h-5 w-5" />
        {datos.no_leidas > 0 && (
          <span className="absolute -right-0.5 -top-0.5 min-w-5 rounded-full bg-red-600 px-1 text-center text-[11px] font-bold leading-5 text-white">
            {datos.no_leidas > 99 ? '99+' : datos.no_leidas}
          </span>
        )}
      </button>
      {abierto && (
        <div className="absolute right-0 z-40 mt-2 w-96 max-w-[90vw] rounded-xl border border-slate-200 bg-white shadow-xl">
          <div className="flex items-center justify-between border-b border-slate-200 px-4 py-2.5">
            <span className="text-sm font-semibold text-slate-800">Notificaciones</span>
            {datos.no_leidas > 0 && (
              <button type="button" onClick={leerTodas} className="inline-flex items-center gap-1 text-xs text-blue-800 hover:underline">
                <CheckCheck className="h-3.5 w-3.5" />Marcar todas como leídas
              </button>
            )}
          </div>
          <ul className="max-h-96 divide-y divide-slate-100 overflow-y-auto">
            {datos.notificaciones.length === 0 && <li className="px-4 py-6 text-center text-sm text-slate-500">Sin notificaciones.</li>}
            {datos.notificaciones.map((n) => (
              <li key={n.id}>
                <button type="button" onClick={() => leer(n)} className={`w-full px-4 py-2.5 text-left text-sm hover:bg-slate-50 ${n.leida ? 'text-slate-500' : 'bg-blue-50/50 text-slate-800'}`}>
                  <span className="flex items-center justify-between gap-2 text-xs">
                    <span className={`rounded px-1.5 py-0.5 font-medium ${n.tipo === 'ALERTA_SLA' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'}`}>{ETIQUETA[n.tipo] || n.tipo}</span>
                    <span className="text-slate-400">{new Date(n.fecha).toLocaleString('es-BO')}</span>
                  </span>
                  <span className="mt-1 block">{n.mensaje}</span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
