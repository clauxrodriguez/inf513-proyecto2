import { BellRing, CheckCircle2 } from 'lucide-react';

export default function PanelAlertas({ alertas, puedeAtender, incluirAtendidas, atendiendo, onCambiarFiltro, onAtender, onSeleccionar }) {
  const activas = alertas.filter((a) => !a.atendida).length;

  return (
    <section className="rounded-xl border border-slate-200 bg-white">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
        <h2 className="flex items-center gap-2 font-semibold text-slate-800">
          <BellRing className="h-4 w-4 text-red-600" />Alertas SLA activas ({activas})
        </h2>
        <label className="flex items-center gap-2 text-xs text-slate-600">
          <input type="checkbox" checked={incluirAtendidas} onChange={(e) => onCambiarFiltro(e.target.checked)} />Mostrar atendidas
        </label>
      </div>
      {alertas.length === 0 ? (
        <p className="px-4 py-6 text-center text-sm text-slate-500">Sin alertas pendientes: todos los trámites están dentro del umbral preventivo.</p>
      ) : (
        <ul className="divide-y divide-slate-100">
          {alertas.map((a) => (
            <li key={a.id} className={`flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm ${a.atendida ? 'opacity-60' : ''}`}>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <span className={`rounded px-2 py-0.5 text-xs font-bold ${a.tipo === 'VENCIDA' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800'}`}>
                    {a.tipo === 'VENCIDA' ? 'VENCIDA (100%)' : 'PREVENTIVA (75%)'}
                  </span>
                  <button type="button" onClick={() => onSeleccionar(a.tramite_id)} className="font-semibold text-blue-900 hover:underline">{a.codigo}</button>
                  <span className="text-xs text-slate-500">{a.estado?.replaceAll('_', ' ')}</span>
                </div>
                <p className="mt-0.5 text-xs text-slate-500">
                  Generada el {new Date(a.fecha).toLocaleString('es-BO')} con {Number(a.horas_transcurridas).toFixed(1)} de {Number(a.plazo_horas)} h
                  {a.horas_consumidas != null && !a.es_final ? ` · ahora ${Number(a.horas_consumidas).toFixed(1)} h` : ''}
                  {a.es_final ? ' · trámite finalizado' : ''}
                </p>
                {a.atendida && <p className="text-xs text-emerald-700">Atendida por {a.atendida_por_nombre || '—'} el {new Date(a.fecha_atencion).toLocaleString('es-BO')}</p>}
              </div>
              {!a.atendida && puedeAtender && (
                <button type="button" onClick={() => onAtender(a)} disabled={atendiendo === a.id}
                  className="inline-flex items-center gap-1 rounded-lg border border-emerald-300 px-2.5 py-1 text-xs font-medium text-emerald-800 hover:bg-emerald-50 disabled:opacity-50">
                  <CheckCircle2 className="h-3.5 w-3.5" />{atendiendo === a.id ? 'Guardando...' : 'Marcar atendida'}
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
