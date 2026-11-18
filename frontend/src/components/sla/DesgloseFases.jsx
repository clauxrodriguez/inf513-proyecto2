import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { X } from 'lucide-react';
import { mensajeError } from '../../api/despachoApi';
import { obtenerDesgloseFases } from '../../api/slaApi';
import Alerta from '../ui/Alerta';

/** SF-1: historial de fases (tramite_tiempo_fase) con las horas hábiles que consumió cada una. */
export default function DesgloseFases({ tramiteId, version, onCerrar }) {
  const [datos, setDatos] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let vigente = true;
    obtenerDesgloseFases(tramiteId)
      .then((res) => { if (vigente) { setDatos(res.data); setError(''); } })
      .catch((err) => { if (vigente) setError(mensajeError(err, 'No se pudo cargar el desglose por fase.')); });
    return () => { vigente = false; };
  }, [tramiteId, version]);

  if (error) return <Alerta mensaje={error} />;
  if (!datos) return <p className="text-sm text-slate-500">Cargando desglose por fase...</p>;
  const plazo = Number(datos.semaforo?.plazo_horas || 72);

  return (
    <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-slate-800">Desglose por fase · {datos.tramite.codigo}</h2>
          <p className="text-sm text-slate-500">
            {datos.horas_en_sla} h hábiles computadas para el SLA de {plazo} h · {datos.horas_detenidas} h excluidas (observado o finalizado)
          </p>
        </div>
        <div className="flex items-center gap-3 text-sm">
          <Link to={`/tramites/${datos.tramite.id}`} className="text-blue-800 hover:underline">Ver trámite</Link>
          <button type="button" onClick={onCerrar} className="inline-flex items-center gap-1 text-slate-600 hover:text-slate-900"><X className="h-4 w-4" />Cerrar</button>
        </div>
      </div>

      <div className="flex h-4 overflow-hidden rounded-full bg-slate-100" title="Distribución de horas hábiles por fase">
        {datos.por_estado.filter((e) => e.horas_habiles > 0).map((e) => (
          <div key={e.estado} className={e.cuenta_sla ? 'bg-blue-800 even:bg-blue-500' : 'bg-amber-300'}
            style={{ width: `${(e.horas_habiles / Math.max(plazo, datos.horas_en_sla + datos.horas_detenidas)) * 100}%` }} title={`${e.estado_nombre}: ${e.horas_habiles} h`} />
        ))}
      </div>

      <div className="grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4">
        {datos.por_estado.map((e) => (
          <div key={e.estado} className={`rounded-lg border p-2.5 ${e.cuenta_sla ? 'border-slate-200' : 'border-amber-200 bg-amber-50'}`}>
            <p className="text-slate-500">{e.estado_nombre}{e.veces > 1 ? ` (×${e.veces})` : ''}</p>
            <p className="text-base font-semibold text-slate-800">{e.horas_habiles} h</p>
            <p className={e.cuenta_sla ? 'text-slate-500' : 'font-medium text-amber-700'}>{e.cuenta_sla ? 'Cuenta para el SLA' : 'Reloj detenido'}</p>
          </div>
        ))}
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
            <tr><th className="py-2 pr-3">Fase</th><th className="py-2 pr-3">Inicio</th><th className="py-2 pr-3">Fin</th><th className="py-2 pr-3">Horas hábiles</th><th className="py-2 pr-3">Responsable</th></tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {datos.fases.map((f) => (
              <tr key={f.id}>
                <td className="py-2 pr-3">{f.estado_nombre}{!f.cuenta_sla && <span className="ml-1.5 rounded bg-amber-50 px-1.5 text-xs text-amber-700">excluida</span>}</td>
                <td className="py-2 pr-3 text-xs text-slate-600">{new Date(f.fecha_inicio).toLocaleString('es-BO')}</td>
                <td className="py-2 pr-3 text-xs text-slate-600">{f.en_curso ? <span className="font-medium text-blue-800">En curso</span> : new Date(f.fecha_fin).toLocaleString('es-BO')}</td>
                <td className="py-2 pr-3 font-medium">{Number(f.horas_habiles).toFixed(2)}</td>
                <td className="py-2 pr-3 text-xs text-slate-600">{f.responsable || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
