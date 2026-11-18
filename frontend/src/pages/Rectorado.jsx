import { useCallback, useEffect, useState } from 'react';
import { Eye, Globe, RefreshCw, Stamp } from 'lucide-react';
import { mensajeError, obtenerBandejaRectorado, visarRectorado } from '../api/despachoApi';
import { InsigniaCalidad, InsigniaPrioridad, InsigniaSemaforo } from '../components/despacho/Insignias';
import ResultadoVerificacion from '../components/despacho/ResultadoVerificacion';
import VisorPdf from '../components/despacho/VisorPdf';
import Alerta from '../components/ui/Alerta';

/** CU5 SF-2 - Bandeja Apostilla / Exterior: visado institucional final de Rectorado previo a Cancillería. */
export default function Rectorado() {
  const [tramites, setTramites] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [activo, setActivo] = useState(null);
  const [version, setVersion] = useState(0);
  const [observacion, setObservacion] = useState('');
  const [guardando, setGuardando] = useState(false);
  const [mensaje, setMensaje] = useState('');
  const [error, setError] = useState('');

  const cargar = useCallback(async () => {
    try {
      const res = await obtenerBandejaRectorado();
      setTramites(res.data);
    } catch (err) {
      setError(mensajeError(err, 'No se pudo cargar la bandeja de Rectorado.'));
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const seleccionado = tramites.find((t) => t.id === activo);

  const visar = async () => {
    setError('');
    setMensaje('');
    setGuardando(true);
    try {
      const res = await visarRectorado(activo, observacion || null);
      setMensaje(res.data.message);
      setObservacion('');
      setVersion((v) => v + 1);
      await cargar();
    } catch (err) {
      setError(mensajeError(err, 'No se pudo aplicar el sello institucional.'));
    } finally {
      setGuardando(false);
    }
  };

  if (cargando) return <p className="text-sm text-slate-500">Cargando bandeja de Rectorado...</p>;

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-bold text-slate-800"><Globe className="h-5 w-5 text-blue-900" />Bandeja Apostilla / Exterior</h1>
          <p className="text-sm text-slate-500">Expedientes firmados digitalmente por el Vicerrectorado con destino al exterior · sello institucional previo a la Apostilla en Cancillería</p>
        </div>
        <button type="button" onClick={cargar} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-white">
          <RefreshCw className="h-4 w-4" />Actualizar
        </button>
      </header>

      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />

      {!tramites.length ? (
        <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No hay expedientes derivados a Rectorado.</div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-600">
              <tr>
                <th className="px-3 py-2.5">Expediente</th><th className="px-3 py-2.5">Solicitante</th><th className="px-3 py-2.5">País / Institución</th>
                <th className="px-3 py-2.5">Firma Vicerrectorado</th><th className="px-3 py-2.5">SLA</th><th className="px-3 py-2.5">Visado</th><th className="px-3 py-2.5 text-right">Acción</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {tramites.map((t) => (
                <tr key={t.id} className={activo === t.id ? 'bg-blue-50/70' : 'hover:bg-slate-50'}>
                  <td className="px-3 py-2.5"><div className="font-semibold text-slate-800">{t.codigo}</div><InsigniaPrioridad prioridad={t.prioridad} /></td>
                  <td className="px-3 py-2.5"><div>{t.solicitante}</div><div className="text-xs text-slate-500">{t.carrera}</div></td>
                  <td className="px-3 py-2.5"><div>{t.pais_destino}</div><div className="text-xs text-slate-500">{t.institucion_destino || '—'}</div></td>
                  <td className="px-3 py-2.5">
                    {t.firma ? (
                      <>
                        <div className="flex items-center gap-1.5">{t.firma.firmante} <InsigniaCalidad calidad={t.firma.calidad} /></div>
                        <div className="text-xs text-slate-500">{new Date(t.firma.fecha_firma).toLocaleString('es-BO')}{t.firma.es_remota ? ' · remota' : ''}</div>
                      </>
                    ) : <span className="text-xs text-red-600">Sin firma</span>}
                  </td>
                  <td className="px-3 py-2.5"><InsigniaSemaforo semaforo={t.semaforo} horas={t.horas_consumidas} plazo={t.plazo_horas} /></td>
                  <td className="px-3 py-2.5">
                    {t.visado
                      ? <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Sellado · pendiente QR (CU6)</span>
                      : <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Pendiente de visado</span>}
                  </td>
                  <td className="px-3 py-2.5 text-right">
                    <button type="button" onClick={() => { setActivo(t.id); setMensaje(''); setError(''); }}
                      className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-white">
                      <Eye className="h-3.5 w-3.5" />Revisar
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {seleccionado && (
        <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
          <VisorPdf tramiteId={seleccionado.id} version={version} />
          <div className="space-y-4">
            <ResultadoVerificacion tramiteId={seleccionado.id} version={version} />
            {!seleccionado.visado ? (
              <div className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
                <h3 className="font-semibold text-slate-800">Visado institucional final</h3>
                <p className="text-xs text-slate-500">Se agrega el sello institucional UAGRM como firma incremental: la firma del Vicerrectorado se conserva intacta.</p>
                <textarea rows="2" maxLength={300} value={observacion} onChange={(e) => setObservacion(e.target.value)} placeholder="Observación (opcional)"
                  className="w-full rounded-lg border border-slate-300 px-3 py-2" />
                <button type="button" onClick={visar} disabled={guardando}
                  className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-900 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-50">
                  <Stamp className="h-4 w-4" />{guardando ? 'Aplicando sello...' : 'Confirmar sello institucional'}
                </button>
              </div>
            ) : (
              <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                Visado por {seleccionado.visado.visado_por} el {new Date(seleccionado.visado.fecha).toLocaleString('es-BO')}.
                {seleccionado.visado.observacion && <p className="mt-1 text-xs">{seleccionado.visado.observacion}</p>}
              </div>
            )}
          </div>
        </section>
      )}
    </div>
  );
}
