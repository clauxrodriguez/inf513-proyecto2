import { useMemo, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { calcularImporte, calcularMontoTotal, moneda, UNIDAD_COBRO } from './calculosTramite';

/**
 * El estudiante elige los documentos del catálogo a legalizar. Cada uno lo emitirá y firmará
 * en origen la unidad correspondiente (cadena de firmas mostrada en cada tarjeta).
 */
export default function SelectorDocumentos({ documentos, seleccionados, onChange }) {
  const [nuevoId, setNuevoId] = useState('');
  const total = useMemo(() => calcularMontoTotal(documentos, seleccionados), [documentos, seleccionados]);

  const agregar = () => {
    if (!nuevoId) return;
    onChange([...seleccionados, { catalogo_documento_id: Number(nuevoId), cantidad_paginas: 1, cantidad_materias: '' }]);
    setNuevoId('');
  };
  const actualizar = (indice, cambios) => onChange(seleccionados.map((item, index) => (index === indice ? { ...item, ...cambios } : item)));
  const quitar = (indice) => onChange(seleccionados.filter((_, index) => index !== indice));

  return (
    <section className="bg-white p-5 rounded-xl border border-slate-200 space-y-4">
      <div>
        <h2 className="font-bold text-slate-800">Documentos a legalizar</h2>
        <p className="text-xs text-slate-500 mt-1">Cada documento lo emite y firma digitalmente la unidad indicada. El importe es referencial: lo cancelará en Caja según el arancel.</p>
      </div>
      <div className="flex flex-col sm:flex-row gap-2">
        <label htmlFor="nuevo-documento" className="sr-only">Documento del catálogo</label>
        <select id="nuevo-documento" value={nuevoId} onChange={(e) => setNuevoId(e.target.value)}
          className="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
          <option value="">Seleccione un documento del catálogo...</option>
          {documentos.filter((doc) => !seleccionados.some((item) => Number(item.catalogo_documento_id) === Number(doc.id)))
            .map((doc) => <option key={doc.id} value={doc.id}>{doc.nombre}</option>)}
        </select>
        <button type="button" onClick={agregar} disabled={!nuevoId}
          className="inline-flex items-center justify-center gap-2 bg-blue-900 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm disabled:opacity-50">
          <Plus className="w-4 h-4" aria-hidden="true" />Agregar documento
        </button>
      </div>
      <div className="space-y-3">
        {seleccionados.map((item, index) => {
          const documento = documentos.find((doc) => Number(doc.id) === Number(item.catalogo_documento_id));
          if (!documento) return null;
          return (
            <article key={documento.id} className="border border-slate-200 rounded-lg p-4 space-y-3">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="font-semibold text-slate-800">{documento.nombre}</p>
                  <p className="text-xs text-slate-500">Emite: {documento.unidad_emisora} · Arancel {moneda(documento.tarifa)} {UNIDAD_COBRO[documento.tipo_cobro]}</p>
                </div>
                <button type="button" onClick={() => quitar(index)} aria-label={`Quitar ${documento.nombre}`}
                  className="p-2 text-red-600 hover:bg-red-50 rounded-lg"><Trash2 className="w-4 h-4" aria-hidden="true" /></button>
              </div>
              <div className="grid sm:grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium text-slate-700 mb-1" htmlFor={`fojas-${documento.id}`}>Cantidad de páginas *</label>
                  <input id={`fojas-${documento.id}`} type="number" min="1" max="500" required value={item.cantidad_paginas}
                    onChange={(e) => actualizar(index, { cantidad_paginas: e.target.value })}
                    className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
                </div>
                {documento.tipo_cobro === 'MATERIA' && (
                  <div>
                    <label className="block text-xs font-medium text-slate-700 mb-1" htmlFor={`materias-${documento.id}`}>Cantidad de materias *</label>
                    <input id={`materias-${documento.id}`} type="number" min="1" max="200" required value={item.cantidad_materias}
                      onChange={(e) => actualizar(index, { cantidad_materias: e.target.value })}
                      className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
                  </div>
                )}
              </div>
              <div className="rounded-lg bg-slate-50 p-3">
                <p className="text-sm font-semibold text-slate-800">Importe referencial: {moneda(calcularImporte(documento, item))}</p>
                <p className="text-xs font-medium text-slate-700 mt-2">Cadena de firmas en origen</p>
                <ol className="list-decimal pl-5 mt-1 text-xs text-slate-600">
                  {documento.firmas_precedentes?.map((firma) => (
                    <li key={`${documento.id}-${firma.orden}`}>{firma.unidad} — {firma.cargo_firmante}{firma.requiere_sello ? ' (con sello)' : ''}</li>
                  ))}
                </ol>
              </div>
            </article>
          );
        })}
        {!seleccionados.length && <p className="text-sm text-slate-500">Agregue al menos un documento del catálogo.</p>}
      </div>
      <div className="flex justify-end border-t border-slate-200 pt-3">
        <p className="font-bold text-slate-800">Total referencial: <span className="text-blue-900">{moneda(total)}</span></p>
      </div>
    </section>
  );
}
