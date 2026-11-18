import { useMemo, useState } from 'react';
import { CheckCircle2, ExternalLink } from 'lucide-react';
import { Link } from 'react-router-dom';
import { abrirArchivo } from '../../api/archivos';
import { obtenerArchivoDocumento, obtenerArchivoExpediente } from '../../api/cuatroApi';
import Alerta from '../ui/Alerta';
import ModalValidarDocumento from './ModalValidarDocumento';

const moneda = (valor) => `${Number(valor || 0).toFixed(2)} Bs`;
const ESTILOS_SEMAFORO = {
  VERDE: 'bg-emerald-50 text-emerald-700',
  AMARILLO: 'bg-amber-50 text-amber-700',
  ROJO: 'bg-red-50 text-red-700',
};

export default function TablaExpedientes({ tramites, esRecepcion, onValidar, rutaDetalle = '/tramites' }) {
  const [filtroEstado, setFiltroEstado] = useState('');
  const [busqueda, setBusqueda] = useState('');
  const [documentoSeleccionado, setDocumentoSeleccionado] = useState(null);
  const [errorArchivo, setErrorArchivo] = useState('');
  const estados = [...new Set(tramites.map((tramite) => tramite.estado).filter(Boolean))];
  const filtrados = useMemo(() => tramites.filter((tramite) => {
    const texto = `${tramite.codigo || ''} ${tramite.estudiante_nombre || ''} ${tramite.estudiante_ci || ''}`.toLowerCase();
    return (!filtroEstado || tramite.estado === filtroEstado) && texto.includes(busqueda.toLowerCase());
  }), [tramites, filtroEstado, busqueda]);

  const abrir = async (obtener) => {
    setErrorArchivo('');
    setErrorArchivo((await abrirArchivo(obtener)) || '');
  };

  return (
    <div className="space-y-4">
      <div className="bg-white p-4 rounded-xl border border-slate-200 flex flex-wrap gap-3">
        <input type="search" value={busqueda} onChange={(e) => setBusqueda(e.target.value)}
          placeholder="Buscar código LEG-2026-000001 o estudiante..."
          className="flex-1 min-w-[240px] border border-slate-300 rounded-lg px-3 py-2 text-sm" />
        <select value={filtroEstado} onChange={(e) => setFiltroEstado(e.target.value)}
          className="border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
          <option value="">Todos los estados</option>
          {estados.map((estado) => <option key={estado} value={estado}>{estado.replaceAll('_', ' ')}</option>)}
        </select>
      </div>
      <div className="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table className="w-full text-sm text-left">
          <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 uppercase text-xs">
            <tr>
              <th className="px-4 py-3">Trámite / Estudiante</th><th className="px-4 py-3">Documentos</th>
              <th className="px-4 py-3">Arancel / Pagado</th><th className="px-4 py-3">Estado / SLA</th>
              <th className="px-4 py-3">Expediente</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {filtrados.map((tramite) => {
              const documentos = tramite.documentos || [];
              const totalEsperado = documentos.reduce((sum, doc) => sum + Number(doc.arancel_esperado || 0), 0);
              const totalPagado = documentos.reduce((sum, doc) => sum + Number(doc.importe_valorado || 0), 0);
              return (
                <tr key={tramite.id} className="align-top hover:bg-slate-50">
                  <td className="px-4 py-3 min-w-[210px]">
                    <Link to={`${rutaDetalle}/${tramite.id}`} className="font-semibold text-blue-900 hover:underline">{tramite.codigo}</Link>
                    <p className="mt-1 text-xs"><Link to={`${rutaDetalle}/${tramite.id}`} className="text-blue-700 hover:underline">Abrir revisión y observaciones</Link></p>
                    <p className="font-medium text-slate-800 mt-1">{tramite.estudiante_nombre}</p>
                    <p className="text-xs text-slate-500">C.I. {tramite.estudiante_ci} · {tramite.registro_universitario || 'Sin registro'}</p>
                    <p className="text-xs text-slate-500">{tramite.carrera || ''} · {tramite.tipo?.replaceAll('_', ' ')}</p>
                    {tramite.destino === 'EXTERIOR' && <p className="text-xs text-slate-500">Destino: {tramite.pais_destino || 'Exterior'}</p>}
                    <p className="text-xs text-slate-500">Total de fojas: {tramite.total_fojas}</p>
                    {tramite.descuento && <p className="mt-1 text-xs font-medium text-blue-800">
                      Descuento: {tramite.descuento.financiamiento?.replaceAll('_', ' ') || 'Pendiente'}
                    </p>}
                  </td>
                  <td className="px-4 py-3 min-w-[300px]">
                    {documentos.length ? <div className="space-y-3">{documentos.map((doc) => (
                      <article key={doc.id} className="border border-slate-200 rounded-lg p-3">
                        <p className="font-medium text-slate-800">{doc.codigo} — {doc.documento_nombre}</p>
                        <p className="text-xs text-slate-600">
                          {doc.cantidad_paginas} páginas{doc.cantidad_materias ? ` · ${doc.cantidad_materias} materias` : ''}
                          {' · Folios '}{doc.folio_inicio ?? '-'}–{doc.folio_fin ?? '-'}
                        </p>
                        <p className="text-xs text-slate-600">Arancel esperado: {moneda(doc.arancel_esperado)} · Valorado: {moneda(doc.importe_valorado)}</p>
                        <p className="text-xs text-slate-600">Recibo: {doc.nro_valorado || 'No registrado'} · Firmas: {doc.firmas_verificadas ? 'Verificadas' : 'Pendientes'} · {doc.estado}</p>
                        {doc.archivo_ruta && <button type="button" onClick={() => abrir(() => obtenerArchivoDocumento(doc.id))} className="inline-flex items-center gap-1 mt-1 text-xs text-blue-800 hover:underline">
                          <ExternalLink className="w-3.5 h-3.5" />Abrir documento
                        </button>}
                        {esRecepcion && doc.estado !== 'VALIDO' && ['INGRESADO', 'EN_REVISION'].includes(tramite.estado) && <button type="button" onClick={() => setDocumentoSeleccionado(doc)}
                          className="flex items-center gap-1.5 mt-2 bg-blue-900 hover:bg-blue-800 text-white px-2.5 py-1.5 rounded-lg text-xs font-medium">
                          <CheckCircle2 className="w-3.5 h-3.5" />Validar documento
                        </button>}
                      </article>
                    ))}</div> : <p className="text-xs text-slate-500">Trámite de descuento de diplomado.</p>}
                  </td>
                  <td className="px-4 py-3 whitespace-nowrap">
                    <p className="font-medium text-slate-800">{moneda(totalEsperado)}</p>
                    <p className="text-xs text-slate-500">Pagado: {moneda(totalPagado)}</p>
                  </td>
                  <td className="px-4 py-3">
                    <span className="block text-xs font-medium text-slate-700">{tramite.estado?.replaceAll('_', ' ')}</span>
                    <span className={`inline-block mt-1 px-2 py-0.5 text-xs rounded-full font-medium ${ESTILOS_SEMAFORO[tramite.semaforo] || 'bg-slate-100 text-slate-600'}`}>
                      {tramite.semaforo || 'Sin semáforo'}
                    </span>
                    <p className="mt-1 text-xs text-slate-500">{Number(tramite.horas_consumidas || 0).toFixed(1)} h consumidas</p>
                  </td>
                  <td className="px-4 py-3 min-w-[130px]">
                    {tramite.expediente?.ruta_pdf
                      ? <button type="button" onClick={() => abrir(() => obtenerArchivoExpediente(tramite.expediente.id))} className="inline-flex items-center gap-1 text-xs text-blue-800 hover:underline">
                        <ExternalLink className="w-3.5 h-3.5" />Ver expediente PDF
                      </button>
                      : <span className="text-xs text-slate-400">Sin expediente</span>}
                  </td>
                </tr>
              );
            })}
            {!filtrados.length && <tr><td colSpan="5" className="px-4 py-8 text-center text-sm text-slate-500">No se encontraron trámites.</td></tr>}
          </tbody>
        </table>
      </div>
      <Alerta mensaje={errorArchivo} />
      {documentoSeleccionado && <ModalValidarDocumento documento={documentoSeleccionado} onConfirmar={onValidar} onCerrar={() => setDocumentoSeleccionado(null)} />}
    </div>
  );
}
