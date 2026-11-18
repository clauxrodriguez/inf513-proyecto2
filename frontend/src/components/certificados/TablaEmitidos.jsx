import { Ban, Eye, PackageCheck } from 'lucide-react';

export default function TablaEmitidos({ certificados, activo, esRecepcion, onVer, onAnular, onEntregar }) {
  if (!certificados.length) {
    return <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">Aún no se emitieron certificados.</div>;
  }

  return (
    <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-600">
          <tr>
            <th className="px-3 py-2.5">Número</th>
            <th className="px-3 py-2.5">Trámite / Titular</th>
            <th className="px-3 py-2.5">Emisión</th>
            <th className="px-3 py-2.5">Estado</th>
            <th className="px-3 py-2.5 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {certificados.map((c) => {
            const vigente = c.estado === 'VIGENTE';
            const constanciaPorEntregar = esRecepcion && vigente && c.tipo === 'CONSTANCIA_DESCUENTO' && !c.fecha_entrega;
            return (
              <tr key={c.id} className={activo === c.id ? 'bg-blue-50/70' : 'hover:bg-slate-50'}>
                <td className="px-3 py-2.5">
                  <div className="font-semibold text-slate-800">{c.numero}</div>
                  <div className="text-xs text-slate-500">{c.tipo === 'CONSTANCIA_DESCUENTO' ? 'Constancia de descuento' : 'Certificado de legalización'}</div>
                </td>
                <td className="px-3 py-2.5">
                  <div>{c.tramite_codigo}</div>
                  <div className="text-xs text-slate-500">{c.titular} · Reg. {c.registro}</div>
                </td>
                <td className="px-3 py-2.5 text-xs text-slate-600">{new Date(c.fecha_emision).toLocaleString('es-BO')}</td>
                <td className="px-3 py-2.5">
                  <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${vigente ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'}`}>{c.estado}</span>
                  {!vigente && <div className="mt-1 max-w-56 text-xs text-slate-500" title={c.motivo_anulacion}>{c.motivo_anulacion}</div>}
                  {c.fecha_entrega && <div className="mt-1 text-xs text-slate-500">Entregado {c.fecha_entrega} · Form. {c.nro_formulario_emitido}</div>}
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex justify-end gap-1.5">
                    <button type="button" onClick={() => onVer(c)} className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-white">
                      <Eye className="h-3.5 w-3.5" />Ver / Imprimir
                    </button>
                    {constanciaPorEntregar && (
                      <button type="button" onClick={() => onEntregar(c)} className="inline-flex items-center gap-1 rounded-lg border border-emerald-300 px-2.5 py-1 text-xs text-emerald-800 hover:bg-emerald-50">
                        <PackageCheck className="h-3.5 w-3.5" />Entrega
                      </button>
                    )}
                    {vigente && (
                      <button type="button" onClick={() => onAnular(c)} className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs text-red-600 hover:bg-red-50">
                        <Ban className="h-3.5 w-3.5" />Anular
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
