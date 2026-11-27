import { useEffect, useMemo, useState } from 'react';
import { obtenerGuiaProcesal } from '../api/cuatroApi';
import Alerta from '../components/ui/Alerta';
import { construirRutaLegalizacion } from '../components/tramites/RequisitosTramite';
import { moneda, UNIDAD_COBRO } from '../components/tramites/calculosTramite';

const NOMBRE_TIPO = { LEGALIZACION: 'Legalización de documentos', DESCUENTO_DIPLOMADO: 'Descuento del 50% para diplomado' };

export default function GuiaProcesal() {
  const [guia, setGuia] = useState({ flujo: [], documentos: [], requisitos: [] });
  const [documentoId, setDocumentoId] = useState('');
  const [destino, setDestino] = useState('NACIONAL');
  const [error, setError] = useState('');
  const [cargando, setCargando] = useState(true);

  useEffect(() => {
    obtenerGuiaProcesal()
      .then((resultado) => setGuia(resultado.data))
      .catch((err) => setError(err.response?.data?.message || 'No se pudo cargar la guía procesal.'))
      .finally(() => setCargando(false));
  }, []);

  const documentoSeleccionado = useMemo(
    () => (guia.documentos || []).find((doc) => String(doc.id) === String(documentoId)) || null,
    [guia.documentos, documentoId],
  );

  const rutaDinamica = useMemo(() => {
    if (!documentoSeleccionado) return null;
    return construirRutaLegalizacion({
      documentosCatalogo: guia.documentos || [],
      seleccionados: [{ catalogo_documento_id: documentoSeleccionado.id, cantidad_paginas: 1, cantidad_materias: 1 }],
      destino,
      paisDestino: '',
    });
  }, [guia.documentos, documentoSeleccionado, destino]);

  const requisitosLegalizacion = useMemo(() => {
    const base = (guia.requisitos || []).filter((r) => r.tipo === 'LEGALIZACION');
    if (!documentoSeleccionado) {
      return base.filter((r) => r.codigo !== 'FOJAS_SEPARADAS' || destino === 'EXTERIOR');
    }
    const unidades = (documentoSeleccionado.firmas_precedentes || []).map((f) => f.unidad);
    return base
      .filter((r) => r.codigo !== 'FOJAS_SEPARADAS' || destino === 'EXTERIOR')
      .map((r) => {
        if (r.codigo === 'CARTA') {
          return { ...r, descripcion: `Solicitud digital dirigida al Vicerrectorado para «${documentoSeleccionado.nombre}» (${destino})` };
        }
        if (r.codigo === 'COMPROBANTE') {
          return { ...r, descripcion: `Comprobante de caja validado por el arancel de ${moneda(documentoSeleccionado.tarifa)} ${UNIDAD_COBRO[documentoSeleccionado.tipo_cobro]}` };
        }
        if (r.codigo === 'FIRMAS_PREVIAS') {
          return { ...r, descripcion: `Firmas y sellos digitales precedentes completos en orden: ${unidades.join(' ? ')}` };
        }
        return r;
      });
  }, [guia.requisitos, documentoSeleccionado, destino]);

  const pasosLegalizacionGeneral = useMemo(
    () => (guia.flujo || [])
      .filter((p) => p.tipo === 'LEGALIZACION' && (p.destino === 'TODOS' || p.destino === destino))
      .map((p, idx) => ({ ...p, ordenMostrado: idx + 1 })),
    [guia.flujo, destino],
  );

  const pasosDescuento = useMemo(
    () => (guia.flujo || []).filter((p) => p.tipo === 'DESCUENTO_DIPLOMADO'),
    [guia.flujo],
  );

  const requisitosDescuento = useMemo(
    () => (guia.requisitos || []).filter((r) => r.tipo === 'DESCUENTO_DIPLOMADO'),
    [guia.requisitos],
  );

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <header>
        <h1 className="text-xl font-bold text-slate-800">Guía Procesal Institucional</h1>
        <p className="text-sm text-slate-500">Seleccione un documento y el destino para consultar los pasos exactos, dónde se emite, qué firmas y sellos requiere y sus requisitos.</p>
      </header>
      {cargando && <p className="text-sm text-slate-500">Cargando guía...</p>}
      {!cargando && error && <Alerta mensaje={error} />}
      {!cargando && !error && <>
        <section className="rounded-xl border border-blue-200 bg-blue-50/70 p-5 space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h2 className="text-base font-bold text-blue-950">Simulador de ruta y requisitos por documento</h2>
              <p className="text-xs text-slate-600">Elija un documento del catálogo o haga clic en cualquiera de las tarjetas de abajo para ver su recorrido real paso a paso.</p>
            </div>
            {documentoSeleccionado && (
              <button type="button" onClick={() => setDocumentoId('')}
                className="rounded-lg border border-blue-300 bg-white px-3 py-1.5 text-xs font-medium text-blue-900 hover:bg-blue-50">
                Ver circuito general
              </button>
            )}
          </div>
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="sm:col-span-2 text-xs font-medium text-slate-700">
              Documento a consultar
              <select value={documentoId} onChange={(e) => setDocumentoId(e.target.value)}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800">
                <option value="">Todos los documentos (circuito general de legalización)...</option>
                {(guia.documentos || []).map((doc) => (
                  <option key={doc.id} value={doc.id}>{doc.nombre} — Emite: {doc.unidad_emisora} ({moneda(doc.tarifa)} {UNIDAD_COBRO[doc.tipo_cobro]})</option>
                ))}
              </select>
            </label>
            <label className="text-xs font-medium text-slate-700">
              Destino del trámite
              <select value={destino} onChange={(e) => setDestino(e.target.value)}
                className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800">
                <option value="NACIONAL">NACIONAL (Bolivia)</option>
                <option value="EXTERIOR">EXTERIOR (con visado Rectorado / Apostilla)</option>
              </select>
            </label>
          </div>
        </section>

        <section className="space-y-4">
          <article className="rounded-xl border border-slate-200 bg-white p-5">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-lg font-semibold text-blue-900">
                  {documentoSeleccionado
                    ? `Paso a paso real para: ${documentoSeleccionado.nombre} (${destino})`
                    : `${NOMBRE_TIPO.LEGALIZACION} (${destino})`}
                </h2>
                {documentoSeleccionado && (
                  <p className="text-xs text-slate-500">
                    Unidad emisora: <span className="font-semibold text-slate-700">{documentoSeleccionado.unidad_emisora}</span>
                    {' · '}Arancel: <span className="font-semibold text-slate-700">{moneda(documentoSeleccionado.tarifa)} {UNIDAD_COBRO[documentoSeleccionado.tipo_cobro]}</span>
                    {' · '}Firmas en origen: <span className="font-semibold text-slate-700">{documentoSeleccionado.firmas_precedentes.length}</span>
                  </p>
                )}
              </div>
            </div>

            {rutaDinamica ? (
              <ol className="grid gap-3 md:grid-cols-2">
                {rutaDinamica.pasos.map((paso) => (
                  <li key={paso.orden} className="rounded-lg border border-slate-200 bg-slate-50 p-3.5">
                    <p className="text-xs font-semibold text-blue-900">Paso {paso.orden} · Unidad / Oficina: {paso.unidad}</p>
                    <p className="mt-0.5 font-medium text-slate-800">{paso.paso}</p>
                    <p className="mt-1 text-sm text-slate-600">{paso.descripcion}</p>
                    {(paso.firma || paso.sello) && (
                      <p className="mt-2 text-xs font-medium text-slate-700">
                        {[paso.firma ? `Firma: ${paso.firma}` : null, paso.sello ? `Sello: ${paso.sello}` : null].filter(Boolean).join(' · ')}
                      </p>
                    )}
                  </li>
                ))}
              </ol>
            ) : (
              <ol className="grid gap-3 md:grid-cols-2">
                {pasosLegalizacionGeneral.map((paso) => (
                  <li key={`LEG-${paso.orden}`} className="rounded-lg bg-slate-50 p-3">
                    <p className="text-xs font-semibold text-slate-500">Paso {paso.ordenMostrado} · {paso.unidad}{paso.destino !== 'TODOS' ? ` · Solo ${paso.destino}` : ''}</p>
                    <p className="font-medium text-slate-800">{paso.paso}</p>
                    <p className="mt-1 text-sm text-slate-600">{paso.descripcion}</p>
                    {(paso.firma_requerida || paso.sello_requerido) && (
                      <p className="mt-2 text-xs text-slate-500">{[paso.firma_requerida, paso.sello_requerido].filter(Boolean).join(' · ')}</p>
                    )}
                  </li>
                ))}
              </ol>
            )}

            <div className="mt-4 border-t border-slate-200 pt-4">
              <h3 className="mb-2 text-sm font-semibold text-slate-800">
                Requisitos verificados en Ventanilla {documentoSeleccionado ? `para «${documentoSeleccionado.nombre}» (${destino})` : `(${destino})`}
              </h3>
              <ul className="grid gap-2 md:grid-cols-2">
                {requisitosLegalizacion.map((requisito) => (
                  <li key={requisito.id} className="rounded-lg border border-slate-200 bg-slate-50/60 p-3 text-sm">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-medium text-slate-800">{requisito.codigo}</span>
                      <span className="text-xs font-medium text-blue-900">{requisito.obligatorio ? 'Obligatorio' : 'Opcional'}</span>
                    </div>
                    <p className="mt-1 text-slate-600">{requisito.descripcion}</p>
                  </li>
                ))}
              </ul>
            </div>
          </article>

          <article className="rounded-xl border border-slate-200 bg-white p-5">
            <h3 className="mb-3 font-semibold text-blue-900">{NOMBRE_TIPO.DESCUENTO_DIPLOMADO}</h3>
            <ol className="grid gap-3 md:grid-cols-2">
              {pasosDescuento.map((paso) => (
                <li key={`DESC-${paso.orden}`} className="rounded-lg bg-slate-50 p-3">
                  <p className="text-xs font-semibold text-slate-500">Paso {paso.orden} · {paso.unidad}</p>
                  <p className="font-medium text-slate-800">{paso.paso}</p>
                  <p className="mt-1 text-sm text-slate-600">{paso.descripcion}</p>
                  {(paso.firma_requerida || paso.sello_requerido) && (
                    <p className="mt-2 text-xs text-slate-500">{[paso.firma_requerida, paso.sello_requerido].filter(Boolean).join(' · ')}</p>
                  )}
                </li>
              ))}
            </ol>
            <div className="mt-4 border-t border-slate-200 pt-4">
              <h4 className="mb-2 text-sm font-semibold text-slate-800">Requisitos para Descuento de Diplomado</h4>
              <ul className="grid gap-2 md:grid-cols-2">
                {requisitosDescuento.map((requisito) => (
                  <li key={requisito.id} className="rounded-lg border border-slate-100 p-3 text-sm">
                    <span className="font-medium text-slate-800">{requisito.obligatorio ? 'Obligatorio' : 'Opcional'}</span>
                    <p className="text-slate-600">{requisito.descripcion}</p>
                  </li>
                ))}
              </ul>
            </div>
          </article>
        </section>

        <section className="space-y-3">
          <h2 className="text-lg font-semibold text-slate-800">Catálogo de documentos legalizables y su cadena de firmas</h2>
          <p className="text-xs text-slate-500">Haga clic en cualquier documento para cargar arriba su circuito completo de pasos y requisitos.</p>
          <div className="grid gap-3 md:grid-cols-2">
            {(guia.documentos || []).map((documento) => {
              const activo = String(documento.id) === String(documentoId);
              return (
                <article key={documento.id}
                  onClick={() => { setDocumentoId(String(documento.id)); window.scrollTo({ top: 0, behavior: 'smooth' }); }}
                  className={`cursor-pointer rounded-xl border p-5 transition ${activo ? 'border-blue-800 bg-blue-50/40 ring-2 ring-blue-800/20' : 'border-slate-200 bg-white hover:border-blue-400'}`}>
                  <div className="flex items-start justify-between gap-2">
                    <h3 className="font-semibold text-slate-800">{documento.nombre}</h3>
                    <span className="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                      {moneda(documento.tarifa)} {UNIDAD_COBRO[documento.tipo_cobro]}
                    </span>
                  </div>
                  <p className="mt-1 text-sm text-slate-500">Dónde se emite: <span className="font-medium text-slate-700">{documento.unidad_emisora}</span></p>
                  <ol className="mt-3 space-y-2">
                    {documento.firmas_precedentes.map((firma) => (
                      <li key={firma.orden} className="rounded-lg bg-blue-50 p-3 text-sm text-blue-950">
                        Paso {firma.orden} en origen: <span className="font-semibold">{firma.unidad}</span> · Firma: {firma.cargo_firmante}{firma.requiere_sello ? ' · con sello institucional' : ''}
                      </li>
                    ))}
                  </ol>
                </article>
              );
            })}
          </div>
        </section>
      </>}
    </div>
  );
}
