import { useState } from 'react';
import { ExternalLink, ShieldCheck } from 'lucide-react';
import { abrirArchivo } from '../../api/archivos';
import { obtenerArchivoDocumento } from '../../api/cuatroApi';
import { verificarFirmasDocumento } from '../../api/tramitesApi';
import Alerta from '../ui/Alerta';
import { calcularImporte, mensajeErrorApi, moneda } from './calculosTramite';

function ResultadoVerificacion({ resultado }) {
  const correcto = resultado.valido && resultado.hash_coincide && resultado.cadena_completa;
  return (
    <div className={`mt-3 rounded-lg border p-3 text-xs ${correcto ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800'}`}>
      <p className="font-semibold">{correcto ? 'Firmas verificadas criptográficamente' : 'La verificación encontró problemas'}</p>
      <ul className="mt-1 space-y-0.5">
        <li>Firmas en el PDF: {resultado.totalFirmas} ({resultado.valido ? 'íntegras y de confianza' : 'alguna no es válida'})</li>
        <li>Firmas registradas en el sistema: {resultado.firmas_registradas}</li>
        <li>Huella SHA-256 del PDF: {resultado.hash_coincide ? 'coincide con la última firma registrada' : 'NO coincide con la registrada'}</li>
        <li>Cadena de firmas en origen: {resultado.cadena_completa ? 'completa' : 'incompleta'}</li>
      </ul>
    </div>
  );
}

/**
 * Documentos del trámite con su cadena de firmas en origen (catalogo_firma_orden), las firmas realizadas
 * y, para la Ventanilla, la verificación criptográfica (firmador) del PDF firmado.
 */
export default function DocumentosOrigen({ documentos, puedeVerificar }) {
  const [verificaciones, setVerificaciones] = useState({});
  const [verificando, setVerificando] = useState(null);
  const [error, setError] = useState('');

  const verificar = async (documentoId) => {
    setError('');
    setVerificando(documentoId);
    try {
      const res = await verificarFirmasDocumento(documentoId);
      setVerificaciones((previas) => ({ ...previas, [documentoId]: res.data }));
    } catch (err) {
      setError(mensajeErrorApi(err, 'No se pudo verificar el documento.'));
    } finally {
      setVerificando(null);
    }
  };

  const abrir = async (documentoId) => {
    setError((await abrirArchivo(() => obtenerArchivoDocumento(documentoId))) || '');
  };

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-5">
      <h2 className="mb-3 text-lg font-semibold text-slate-800">Documentos y firmas en origen</h2>
      <div className="space-y-3">
        {documentos.map((documento) => {
          const firmados = new Set(documento.firmas_origen.map((firma) => Number(firma.orden_firma)));
          return (
            <article key={documento.id} className="rounded-lg border border-slate-200 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h3 className="font-semibold text-slate-800">{documento.documento_nombre}</h3>
                  <p className="text-xs text-slate-500">
                    {documento.cantidad_paginas} página(s){documento.cantidad_materias ? ` · ${documento.cantidad_materias} materia(s)` : ''}
                    {documento.folio_inicio ? ` · Folios ${documento.folio_inicio}–${documento.folio_fin}` : ''}
                    {' · '}Arancel {moneda(calcularImporte(documento, documento))} · {documento.estado.replaceAll('_', ' ')}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                  {documento.archivo_ruta && (
                    <button type="button" onClick={() => abrir(documento.id)} className="inline-flex items-center gap-1 text-sm text-blue-800 hover:underline">
                      <ExternalLink className="h-4 w-4" aria-hidden="true" />Ver PDF
                    </button>
                  )}
                  {puedeVerificar && documento.archivo_ruta && (
                    <button type="button" disabled={verificando === documento.id} onClick={() => verificar(documento.id)}
                      className="inline-flex items-center gap-1.5 rounded-lg bg-blue-900 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-blue-800 disabled:opacity-50">
                      <ShieldCheck className="h-3.5 w-3.5" aria-hidden="true" />{verificando === documento.id ? 'Verificando...' : 'Verificar firmas'}
                    </button>
                  )}
                </div>
              </div>
              <ol className="mt-3 flex flex-wrap gap-2">
                {documento.firmas_precedentes.map((paso) => {
                  const firma = documento.firmas_origen.find((f) => Number(f.orden_firma) === Number(paso.orden));
                  return (
                    <li key={paso.orden} className={`rounded-full px-3 py-1 text-xs ${firmados.has(Number(paso.orden)) ? 'bg-emerald-50 text-emerald-800' : 'bg-slate-100 text-slate-600'}`}
                      title={firma ? `Firmado por ${firma.firmante} el ${firma.fecha_firma}` : 'Pendiente'}>
                      {firmados.has(Number(paso.orden)) ? '✓' : '○'} {paso.orden}. {paso.unidad} · {paso.cargo_firmante}
                    </li>
                  );
                })}
              </ol>
              {verificaciones[documento.id] && <ResultadoVerificacion resultado={verificaciones[documento.id]} />}
            </article>
          );
        })}
        {!documentos.length && <p className="text-sm text-slate-500">Este trámite no tiene documentos del catálogo.</p>}
      </div>
      <Alerta mensaje={error} className="mt-3" />
    </section>
  );
}
