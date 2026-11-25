import { useState } from 'react';
import { ExternalLink } from 'lucide-react';
import { Link } from 'react-router-dom';
import { abrirArchivo } from '../../api/archivos';
import { obtenerArchivoExpediente } from '../../api/cuatroApi';
import Alerta from '../ui/Alerta';
import { moneda } from './calculosTramite';

const ESTILOS_SEMAFORO = {
  VERDE: 'bg-emerald-50 text-emerald-700',
  AMARILLO: 'bg-amber-50 text-amber-700',
  ROJO: 'bg-red-50 text-red-700',
};

export default function TablaExpedientes({ tramites, rutaDetalle = '/tramites' }) {
  const [filtroEstado, setFiltroEstado] = useState('');
  const [busqueda, setBusqueda] = useState('');
  const [errorArchivo, setErrorArchivo] = useState('');
  const estados = [...new Set(tramites.map((tramite) => tramite.estado).filter(Boolean))];
  const filtrados = tramites.filter((tramite) => {
    const texto = `${tramite.codigo || ''} ${tramite.estudiante_nombre || ''} ${tramite.estudiante_ci || ''} ${tramite.registro_universitario || ''}`.toLowerCase();
    return (!filtroEstado || tramite.estado === filtroEstado) && texto.includes(busqueda.toLowerCase());
  });

  const abrir = async (obtener) => {
    setErrorArchivo((await abrirArchivo(obtener)) || '');
  };

  return (
    <div className="space-y-4">
      <div className="bg-white p-4 rounded-xl border border-slate-200 flex flex-wrap gap-3">
        <label htmlFor="buscar-tramite" className="sr-only">Buscar trámite</label>
        <input id="buscar-tramite" type="search" value={busqueda} onChange={(e) => setBusqueda(e.target.value)}
          placeholder="Buscar por código, estudiante, C.I. o registro..."
          className="flex-1 min-w-[240px] border border-slate-300 rounded-lg px-3 py-2 text-sm" />
        <label htmlFor="filtro-estado" className="sr-only">Filtrar por estado</label>
        <select id="filtro-estado" value={filtroEstado} onChange={(e) => setFiltroEstado(e.target.value)}
          className="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
          <option value="">Todos los estados</option>
          {estados.map((estado) => <option key={estado} value={estado}>{estado.replaceAll('_', ' ')}</option>)}
        </select>
      </div>
      <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table className="w-full text-sm text-left">
          <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 uppercase text-xs">
            <tr>
              <th className="px-4 py-3">Trámite / Estudiante</th><th className="px-4 py-3">Documentos (firma en origen)</th>
              <th className="px-4 py-3">Arancel / Pagado</th><th className="px-4 py-3">Estado / SLA</th>
              <th className="px-4 py-3">Expediente</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {filtrados.map((tramite) => {
              const documentos = tramite.documentos || [];
              const firmados = documentos.filter((doc) => doc.firmas_origen_ok).length;
              const pagoCompleto = Number(tramite.importe_pagado) >= Number(tramite.importe_esperado);
              return (
                <tr key={tramite.id} className="align-top hover:bg-slate-50">
                  <td className="px-4 py-3 min-w-[210px]">
                    <Link to={`${rutaDetalle}/${tramite.id}`} className="font-semibold text-blue-900 hover:underline">{tramite.codigo}</Link>
                    <p className="font-medium text-slate-800 mt-1">{tramite.estudiante_nombre}</p>
                    <p className="text-xs text-slate-500">C.I. {tramite.estudiante_ci} · {tramite.registro_universitario || 'Sin registro'}</p>
                    <p className="text-xs text-slate-500">{tramite.carrera} · {tramite.tipo_nombre}</p>
                    {tramite.destino === 'EXTERIOR' && <p className="text-xs text-slate-500">Destino: {tramite.pais_destino}</p>}
                    {tramite.descuento && <p className="mt-1 text-xs font-medium text-blue-800">
                      Descuento: {tramite.descuento.financiamiento?.replaceAll('_', ' ') || 'Pendiente'}
                    </p>}
                  </td>
                  <td className="px-4 py-3 min-w-[260px]">
                    {documentos.length ? (
                      <>
                        <p className="text-xs font-medium text-slate-700">{firmados} de {documentos.length} con la cadena de firmas completa</p>
                        <ul className="mt-1 space-y-1">
                          {documentos.map((doc) => (
                            <li key={doc.id} className="text-xs text-slate-600">
                              <span className={doc.firmas_origen_ok ? 'text-emerald-700' : 'text-amber-700'}>{doc.firmas_origen_ok ? '✓' : '…'}</span> {doc.documento_nombre}
                              {' · '}{doc.cantidad_paginas} pág.{doc.folio_inicio ? ` · fs. ${doc.folio_inicio}–${doc.folio_fin}` : ''}
                            </li>
                          ))}
                        </ul>
                      </>
                    ) : <p className="text-xs text-slate-500">Descuento de diplomado (sin documentos de origen).</p>}
                  </td>
                  <td className="px-4 py-3 whitespace-nowrap">
                    <p className="font-medium text-slate-800">{moneda(tramite.importe_esperado)}</p>
                    <p className={`text-xs ${pagoCompleto ? 'text-emerald-700' : 'text-slate-500'}`}>Pagado: {moneda(tramite.importe_pagado)}</p>
                  </td>
                  <td className="px-4 py-3">
                    <span className="block text-xs font-medium text-slate-700">{tramite.estado?.replaceAll('_', ' ')}</span>
                    <span className={`inline-block mt-1 px-2 py-0.5 text-xs rounded-full font-medium ${ESTILOS_SEMAFORO[tramite.semaforo] || 'bg-slate-100 text-slate-600'}`}>
                      {tramite.semaforo || 'Sin semáforo'}
                    </span>
                    <p className="mt-1 text-xs text-slate-500">{Number(tramite.horas_consumidas || 0).toFixed(1)} h hábiles</p>
                  </td>
                  <td className="px-4 py-3 min-w-[130px]">
                    {tramite.expediente
                      ? <button type="button" onClick={() => abrir(() => obtenerArchivoExpediente(tramite.expediente.id))} className="inline-flex items-center gap-1 text-xs text-blue-800 hover:underline">
                        <ExternalLink className="w-3.5 h-3.5" aria-hidden="true" />Versión {tramite.expediente.version} ({tramite.expediente.total_fojas} fs.)
                      </button>
                      : <span className="text-xs text-slate-400">Sin compilar</span>}
                  </td>
                </tr>
              );
            })}
            {!filtrados.length && <tr><td colSpan="5" className="px-4 py-8 text-center text-sm text-slate-500">No se encontraron trámites.</td></tr>}
          </tbody>
        </table>
      </div>
      <Alerta mensaje={errorArchivo} />
    </div>
  );
}
