import { Pause, Timer } from 'lucide-react';
import { InsigniaPrioridad } from '../despacho/Insignias';

const BARRA = { ROJO: 'bg-red-500', AMARILLO: 'bg-amber-400', VERDE: 'bg-emerald-500' };
const PUNTO = { ROJO: 'bg-red-500', AMARILLO: 'bg-amber-400', VERDE: 'bg-emerald-500' };

export default function TablaSemaforo({ tramites, activo, onSeleccionar }) {
  if (!tramites.length) {
    return <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No hay trámites en curso con esos filtros.</div>;
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-600">
          <tr>
            <th className="px-3 py-2.5">Semáforo</th>
            <th className="px-3 py-2.5">Trámite</th>
            <th className="px-3 py-2.5">Fase actual</th>
            <th className="px-3 py-2.5 w-64">Consumo del plazo (horas hábiles)</th>
            <th className="px-3 py-2.5">Reloj</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {tramites.map((t) => {
            const porcentaje = Math.min(100, Number(t.porcentaje || 0));
            return (
              <tr key={t.tramite_id} onClick={() => onSeleccionar(t.tramite_id)}
                className={`cursor-pointer ${activo === t.tramite_id ? 'bg-blue-50/70' : 'hover:bg-slate-50'}`}>
                <td className="px-3 py-2.5">
                  <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700">
                    <span className={`h-3 w-3 rounded-full ${PUNTO[t.semaforo]}`} />{t.semaforo}
                  </span>
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex items-center gap-1.5 font-semibold text-slate-800">{t.codigo} <InsigniaPrioridad prioridad={t.prioridad} /></div>
                  <div className="text-xs text-slate-500">{t.solicitante} · {t.tipo?.replaceAll('_', ' ')} · {t.destino || 'NACIONAL'}</div>
                </td>
                <td className="px-3 py-2.5">
                  <div className="text-slate-700">{t.estado_nombre}</div>
                  {t.fase_desde && <div className="text-xs text-slate-500">desde {new Date(t.fase_desde).toLocaleString('es-BO')}</div>}
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex justify-between text-xs text-slate-600">
                    <span>{Number(t.horas_consumidas).toFixed(1)} / {Number(t.plazo_horas)} h</span>
                    <span className="font-medium">{Number(t.porcentaje).toFixed(1)}%</span>
                  </div>
                  <div className="mt-1 h-2 rounded-full bg-slate-200">
                    <div className={`h-2 rounded-full ${BARRA[t.semaforo]}`} style={{ width: `${porcentaje}%` }} />
                  </div>
                </td>
                <td className="px-3 py-2.5 text-xs">
                  {t.reloj_activo
                    ? <span className="inline-flex items-center gap-1 text-slate-600"><Timer className="h-3.5 w-3.5" />Corriendo</span>
                    : <span className="inline-flex items-center gap-1 font-medium text-amber-700"><Pause className="h-3.5 w-3.5" />Detenido</span>}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
