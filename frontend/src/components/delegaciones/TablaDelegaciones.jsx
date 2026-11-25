import { Ban } from 'lucide-react';

export default function TablaDelegaciones({ datos, onRevocar }) {
  return (
    <>
      <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <div className="px-4 py-3 border-b border-slate-200 font-bold text-slate-800 text-sm">Historial de suplencias</div>
        <table className="w-full text-sm text-left">
          <thead className="bg-slate-50 text-xs uppercase text-slate-600 border-b border-slate-200"><tr>
            <th className="px-4 py-2.5">Resolución</th><th className="px-4 py-2.5">Motivo</th><th className="px-4 py-2.5">Titular</th>
            <th className="px-4 py-2.5">Suplente interino</th><th className="px-4 py-2.5">Vigencia</th><th className="px-4 py-2.5">Estado</th>
            <th className="px-4 py-2.5 text-right">Acción</th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {datos.delegaciones.map((d) => (
              <tr key={d.id}>
                <td className="px-4 py-2.5"><div className="font-medium text-slate-800">{d.nro_resolucion}</div><div className="text-xs text-slate-500">{d.fecha_resolucion}</div></td>
                <td className="px-4 py-2.5"><div className="text-slate-700">{d.motivo?.replaceAll('_', ' ')}</div><div className="text-xs text-slate-500">{d.detalle_motivo}</div></td>
                <td className="px-4 py-2.5">{d.titular_nombre}</td>
                <td className="px-4 py-2.5"><div>{d.suplente_nombre}</div><div className="text-xs text-slate-500">{d.facultad_suplente}</div></td>
                <td className="px-4 py-2.5 text-xs text-slate-600"><div>Desde {d.fecha_inicio}</div><div>Hasta {d.fecha_fin}</div></td>
                <td className="px-4 py-2.5">
                  <span className={`px-2 py-0.5 text-xs rounded-full font-medium ${d.estado === 'ACTIVA' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{d.estado}</span>
                  {d.vigente_hoy && <div className="mt-1 text-xs font-medium text-emerald-700">Vigente hoy</div>}
                </td>
                <td className="px-4 py-2.5 text-right">
                  {d.estado === 'ACTIVA' && (
                    <button type="button" onClick={() => onRevocar(d.id)} className="inline-flex items-center gap-1 text-xs text-red-600 hover:bg-red-50 px-2.5 py-1 rounded">
                      <Ban className="w-3.5 h-3.5" aria-hidden="true" />Revocar
                    </button>
                  )}
                </td>
              </tr>
            ))}
            {!datos.delegaciones.length && <tr><td colSpan="7" className="px-4 py-6 text-center text-sm text-slate-500">No hay suplencias registradas.</td></tr>}
          </tbody>
        </table>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <div className="px-4 py-3 border-b border-slate-200">
          <h2 className="font-bold text-slate-800 text-sm">Certificados digitales de firma de las autoridades</h2>
          <p className="text-xs text-slate-500">Los emite una Entidad Certificadora autorizada por la ATT (ADSIB, Digicert). Cada autoridad registra el suyo al firmar por primera vez con Jacobitus. Verifique aquí que el suplente tenga un certificado vigente antes de delegar.</p>
        </div>
        <table className="w-full text-sm text-left">
          <thead className="bg-slate-50 text-xs uppercase text-slate-600 border-b border-slate-200"><tr>
            <th className="px-4 py-2.5">Autoridad</th><th className="px-4 py-2.5">Número de serie</th><th className="px-4 py-2.5">Emisor</th>
            <th className="px-4 py-2.5">Emisión</th><th className="px-4 py-2.5">Expiración</th><th className="px-4 py-2.5">Estado</th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {datos.certificados.map((c) => (
              <tr key={c.id}>
                <td className="px-4 py-2.5"><div className="font-medium">{c.usuario_nombre}</div><div className="text-xs text-slate-500">{c.roles}</div></td>
                <td className="px-4 py-2.5 font-mono text-xs">{c.numero_serie}</td>
                <td className="px-4 py-2.5 text-xs text-slate-600">{c.emisor}</td>
                <td className="px-4 py-2.5 text-xs">{c.fecha_emision}</td>
                <td className="px-4 py-2.5 text-xs">{c.fecha_expiracion}</td>
                <td className="px-4 py-2.5">
                  <span className={`px-2 py-0.5 text-xs rounded-full font-medium ${c.vigente ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{c.vigente ? 'Vigente' : 'Inactivo'}</span>
                </td>
              </tr>
            ))}
            {!datos.certificados.length && <tr><td colSpan="6" className="px-4 py-6 text-center text-sm text-slate-500">Ninguna autoridad registró todavía su certificado de firma.</td></tr>}
          </tbody>
        </table>
      </div>
    </>
  );
}
