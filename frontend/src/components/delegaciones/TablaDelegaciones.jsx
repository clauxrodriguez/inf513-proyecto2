import { useState } from 'react';
import { Ban, FileText } from 'lucide-react';
import { abrirArchivo } from '../../api/archivos';
import { obtenerResolucion } from '../../api/delegacionesApi';
import Alerta from '../ui/Alerta';

export default function TablaDelegaciones({ datos, esRectorado, onRevocar }) {
  const [error, setError] = useState('');
  const verResolucion = async (id) => setError((await abrirArchivo(() => obtenerResolucion(id))) || '');

  return (
    <>
      <Alerta mensaje={error} />
      <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <div className="px-4 py-3 border-b border-slate-200 font-bold text-slate-800 text-sm">Historial de Suplencias y Resoluciones</div>
        <table className="w-full text-sm text-left">
          <thead className="bg-slate-50 text-xs uppercase text-slate-600 border-b border-slate-200"><tr>
            <th className="px-4 py-2.5">Resolución</th><th className="px-4 py-2.5">Motivo</th><th className="px-4 py-2.5">Titular</th><th className="px-4 py-2.5">Suplente Interino</th>
            <th className="px-4 py-2.5">Vigencia</th><th className="px-4 py-2.5">Respaldo</th><th className="px-4 py-2.5">Estado</th>
            {esRectorado && <th className="px-4 py-2.5 text-right">Acción</th>}
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {datos.delegaciones.map((d) => <tr key={d.id}>
              <td className="px-4 py-2.5"><div className="font-medium text-slate-800">{d.nro_resolucion}</div><div className="text-xs text-slate-500">{d.tipo_resolucion}</div></td>
              <td className="px-4 py-2.5"><div className="text-slate-700">{d.motivo?.replaceAll('_', ' ')}</div><div className="text-xs text-slate-500">{d.detalle_motivo}</div></td>
              <td className="px-4 py-2.5">{d.titular_nombre}</td>
              <td className="px-4 py-2.5"><div>{d.suplente_nombre}</div><div className="text-xs text-slate-500">{d.facultad_suplente}</div></td>
              <td className="px-4 py-2.5 text-xs text-slate-600"><div>Desde: {d.fecha_inicio}</div><div>Hasta: {d.fecha_fin}</div></td>
              <td className="px-4 py-2.5">{d.archivo_resolucion_url
                ? <button type="button" onClick={() => verResolucion(d.id)} className="inline-flex items-center gap-1 text-xs text-blue-800 hover:underline"><FileText className="w-3.5 h-3.5" />Ver PDF</button>
                : <span className="text-xs text-slate-400">Sin PDF</span>}</td>
              <td className="px-4 py-2.5"><span className={`px-2 py-0.5 text-xs rounded-full font-medium ${d.estado === 'ACTIVA' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>{d.estado}</span></td>
              {esRectorado && <td className="px-4 py-2.5 text-right">{d.estado === 'ACTIVA' && <button onClick={() => onRevocar(d.id)} className="inline-flex items-center gap-1 text-xs text-red-600 hover:bg-red-50 px-2.5 py-1 rounded"><Ban className="w-3.5 h-3.5" />Revocar</button>}</td>}
            </tr>)}
          </tbody>
        </table>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <div className="px-4 py-3 border-b border-slate-200 font-bold text-slate-800 text-sm">Certificados Digitales ADSIB</div>
        <table className="w-full text-sm text-left">
          <thead className="bg-slate-50 text-xs uppercase text-slate-600 border-b border-slate-200"><tr>
            <th className="px-4 py-2.5">Autoridad</th><th className="px-4 py-2.5">Número de serie</th><th className="px-4 py-2.5">Emisor</th><th className="px-4 py-2.5">Fecha de emisión</th><th className="px-4 py-2.5">Fecha de expiración</th><th className="px-4 py-2.5">Estado</th>
          </tr></thead>
          <tbody className="divide-y divide-slate-100">
            {datos.certificados.map((c) => <tr key={c.id}>
              <td className="px-4 py-2.5 font-medium">{c.usuario_nombre} ({c.rol})</td><td className="px-4 py-2.5 font-mono text-xs">{c.numero_serie}</td>
              <td className="px-4 py-2.5 text-xs text-slate-600">{c.emisor}</td><td className="px-4 py-2.5 text-xs">{c.fecha_emision}</td>
              <td className="px-4 py-2.5 text-xs">{c.fecha_expiracion}</td>
              <td className="px-4 py-2.5"><span className={`px-2 py-0.5 text-xs rounded-full font-medium ${c.activo ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>{c.activo ? 'Vigente' : 'Inactivo'}</span></td>
            </tr>)}
          </tbody>
        </table>
      </div>
    </>
  );
}
