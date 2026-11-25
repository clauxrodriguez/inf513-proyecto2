import { QrCode } from 'lucide-react';
import { InsigniaCalidad, InsigniaDestino, InsigniaPrioridad } from '../despacho/Insignias';

export default function TablaListos({ tramites, emitiendo, onEmitir }) {
  if (!tramites.length) {
    return <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No hay expedientes firmados pendientes de emisión.</div>;
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-600">
          <tr>
            <th className="px-3 py-2.5">Expediente</th>
            <th className="px-3 py-2.5">Solicitante</th>
            <th className="px-3 py-2.5">Destino</th>
            <th className="px-3 py-2.5">Firma digital</th>
            <th className="px-3 py-2.5">Documento a emitir</th>
            <th className="px-3 py-2.5 text-right">Acción</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {tramites.map((t) => (
            <tr key={t.id} className="hover:bg-slate-50">
              <td className="px-3 py-2.5">
                <div className="font-semibold text-slate-800">{t.codigo}</div>
                <div className="flex items-center gap-1.5 text-xs text-slate-500"><InsigniaPrioridad prioridad={t.prioridad} />{t.tipo?.replaceAll('_', ' ')}</div>
              </td>
              <td className="px-3 py-2.5">
                <div>{t.solicitante}</div>
                <div className="text-xs text-slate-500">Reg. {t.registro} · {t.carrera}</div>
              </td>
              <td className="px-3 py-2.5"><InsigniaDestino destino={t.destino || 'NACIONAL'} pais={t.pais_destino} /></td>
              <td className="px-3 py-2.5">
                {t.firma ? (
                  <>
                    <div className="flex items-center gap-1.5">{t.firma.firmante} <InsigniaCalidad calidad={t.firma.delegacion_id ? 'INTERINO' : 'TITULAR'} /></div>
                    <div className="text-xs text-slate-500">{new Date(t.firma.fecha_firma).toLocaleString('es-BO')}{t.firma.es_remota ? ' · remota' : ''}</div>
                  </>
                ) : <span className="text-xs text-red-600">Sin firma</span>}
              </td>
              <td className="px-3 py-2.5 text-xs">
                <div className="font-medium text-slate-700">{t.tipo === 'DESCUENTO_DIPLOMADO' ? 'Constancia de descuento (CD-)' : 'Certificado de legalización (CL-)'}</div>
                {t.es_reemision && <span className="mt-0.5 inline-block rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-700">Reemisión (anterior anulado)</span>}
                {t.destino === 'EXTERIOR' && !t.es_reemision && <span className="mt-0.5 inline-block rounded bg-emerald-50 px-1.5 py-0.5 font-medium text-emerald-700">Firmado por Rectorado</span>}
              </td>
              <td className="px-3 py-2.5 text-right">
                <button type="button" onClick={() => onEmitir(t)} disabled={emitiendo !== null}
                  className="inline-flex items-center gap-1.5 rounded-lg bg-blue-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-800 disabled:opacity-40">
                  <QrCode className="h-3.5 w-3.5" />{emitiendo === t.id ? 'Emitiendo...' : 'Emitir con QR'}
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
