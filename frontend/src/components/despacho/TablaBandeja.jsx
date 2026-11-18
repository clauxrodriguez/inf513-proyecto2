import { Eye, PenTool } from 'lucide-react';
import { InsigniaDestino, InsigniaPrioridad, InsigniaSemaforo } from './Insignias';

export default function TablaBandeja({ expedientes, seleccion, activo, puedeFirmar, onAlternar, onAlternarTodos, onRevisar, onFirmar }) {
  const todos = expedientes.length > 0 && seleccion.length === expedientes.length;

  if (!expedientes.length) {
    return (
      <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
        No hay expedientes pendientes de firma en la bandeja de despacho.
      </div>
    );
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-600">
          <tr>
            <th className="px-3 py-2.5">
              <input type="checkbox" aria-label="Seleccionar todos" checked={todos} onChange={onAlternarTodos} disabled={!puedeFirmar} />
            </th>
            <th className="px-3 py-2.5">Expediente</th>
            <th className="px-3 py-2.5">Solicitante</th>
            <th className="px-3 py-2.5">Destino</th>
            <th className="px-3 py-2.5">Prioridad</th>
            <th className="px-3 py-2.5">SLA 72 h</th>
            <th className="px-3 py-2.5">Fojas</th>
            <th className="px-3 py-2.5 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {expedientes.map((exp) => (
            <tr key={exp.tramite_id} className={activo === exp.tramite_id ? 'bg-blue-50/70' : 'hover:bg-slate-50'}>
              <td className="px-3 py-2.5">
                <input type="checkbox" aria-label={`Seleccionar ${exp.codigo}`} checked={seleccion.includes(exp.tramite_id)}
                  onChange={() => onAlternar(exp.tramite_id)} disabled={!puedeFirmar} />
              </td>
              <td className="px-3 py-2.5">
                <div className="font-semibold text-slate-800">{exp.codigo}</div>
                <div className="text-xs text-slate-500">{exp.tipo?.replaceAll('_', ' ')} · v{exp.expediente_version || 1}</div>
              </td>
              <td className="px-3 py-2.5">
                <div className="text-slate-800">{exp.solicitante}</div>
                <div className="text-xs text-slate-500">{exp.carrera}</div>
              </td>
              <td className="px-3 py-2.5"><InsigniaDestino destino={exp.destino} pais={exp.pais_destino} /></td>
              <td className="px-3 py-2.5"><InsigniaPrioridad prioridad={exp.prioridad} /></td>
              <td className="px-3 py-2.5"><InsigniaSemaforo semaforo={exp.semaforo} horas={exp.horas_consumidas} plazo={exp.plazo_horas} /></td>
              <td className="px-3 py-2.5 text-slate-700">{exp.total_fojas}</td>
              <td className="px-3 py-2.5">
                <div className="flex justify-end gap-1.5">
                  <button type="button" onClick={() => onRevisar(exp.tramite_id)} className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-white">
                    <Eye className="h-3.5 w-3.5" />Revisar
                  </button>
                  <button type="button" onClick={() => onFirmar([exp.tramite_id])} disabled={!puedeFirmar}
                    className="inline-flex items-center gap-1 rounded-lg bg-blue-900 px-2.5 py-1 text-xs font-medium text-white hover:bg-blue-800 disabled:opacity-40">
                    <PenTool className="h-3.5 w-3.5" />Firmar
                  </button>
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
