import { useEffect, useState } from 'react';
import { CheckCircle2, Fingerprint, PenTool, Undo2, XCircle } from 'lucide-react';
import { obtenerDetalleTramite, registrarObservacion } from '../../api/cuatroApi';
import { mensajeError } from '../../api/despachoApi';
import FormObservacion from '../tramites/FormObservacion';
import Alerta from '../ui/Alerta';
import ResultadoVerificacion from './ResultadoVerificacion';

/**
 * Detalle del legajo junto al visor: fojas, recibos, cadena de firmas en origen, verificación criptográfica
 * del PDF vigente y hash SHA-256. Permite devolver el expediente con una observación.
 */
export default function DetalleLegajo({ tramiteId, recarga, puedeFirmar, onFirmar, onDevuelto }) {
  const [tramite, setTramite] = useState(null);
  const [observando, setObservando] = useState(false);
  const [error, setError] = useState('');
  const [guardando, setGuardando] = useState(false);

  useEffect(() => {
    let vigente = true;
    obtenerDetalleTramite(tramiteId)
      .then((res) => { if (vigente) setTramite(res.data); })
      .catch((err) => { if (vigente) setError(mensajeError(err, 'No se pudo cargar el detalle del expediente.')); });
    return () => { vigente = false; };
  }, [tramiteId, recarga]);

  const devolver = async (payload) => {
    setError('');
    setGuardando(true);
    try {
      const res = await registrarObservacion(tramiteId, payload);
      onDevuelto(res.data.message);
      return true;
    } catch (err) {
      setError(mensajeError(err, 'No se pudo registrar la observación.'));
      return false;
    } finally {
      setGuardando(false);
    }
  };

  if (!tramite) return error ? <Alerta mensaje={error} /> : <p className="text-sm text-slate-500">Cargando detalle...</p>;
  const expediente = (tramite.expedientes || []).find((item) => item.vigente);
  const documentos = (tramite.documentos || []).filter((item) => item.estado !== 'RECHAZADO');

  return (
    <div className="space-y-4">
      <section className="rounded-xl border border-slate-200 bg-white p-4 text-sm">
        <h3 className="font-semibold text-slate-800">{tramite.codigo}</h3>
        <p className="text-xs text-slate-500">{tramite.estudiante_nombre} · C.I. {tramite.estudiante_ci} · {tramite.carrera}</p>
        <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
          <div><dt className="text-slate-500">Versión del legajo</dt><dd className="font-medium text-slate-800">v{expediente?.version || 1}</dd></div>
          <div><dt className="text-slate-500">Total de fojas</dt><dd className="font-medium text-slate-800">{tramite.total_fojas}</dd></div>
          <div><dt className="text-slate-500">Destino</dt><dd className="font-medium text-slate-800">{tramite.destino}{tramite.pais_destino ? ` · ${tramite.pais_destino}` : ''}</dd></div>
          <div><dt className="text-slate-500">Institución</dt><dd className="font-medium text-slate-800">{tramite.institucion_destino || '—'}</dd></div>
        </dl>
        {expediente?.hash_sha256 && (
          <p className="mt-3 flex items-start gap-1.5 break-all rounded bg-slate-50 p-2 font-mono text-[11px] text-slate-600">
            <Fingerprint className="h-3.5 w-3.5 shrink-0 text-slate-400" />SHA-256: {expediente.hash_sha256}
          </p>
        )}
      </section>

      {tramite.tipo === 'LEGALIZACION' ? (
        <section className="rounded-xl border border-slate-200 bg-white p-4 text-sm">
          <h3 className="mb-2 font-semibold text-slate-800">Documentos y firmas en origen</h3>
          <p className="mb-2 text-xs text-slate-600">
            Recibos de Caja: Bs {Number(tramite.importe_pagado).toFixed(2)} de Bs {Number(tramite.importe_esperado).toFixed(2)} esperados
          </p>
          <ul className="space-y-2.5">
            {documentos.map((doc) => (
              <li key={doc.id} className="border-b border-slate-100 pb-2 last:border-0">
                <div className="flex items-start justify-between gap-2">
                  <span className="font-medium text-slate-800">{doc.documento_nombre}</span>
                  <span className="shrink-0 text-xs text-slate-500">Fojas {doc.folio_inicio}–{doc.folio_fin}</span>
                </div>
                <span className={`mt-1 inline-flex items-center gap-1 text-xs ${doc.firmas_origen_ok ? 'text-emerald-700' : 'text-red-600'}`}>
                  {doc.firmas_origen_ok ? <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" /> : <XCircle className="h-3.5 w-3.5" aria-hidden="true" />}
                  Cadena de firmas {doc.firmas_origen_ok ? 'completa' : 'incompleta'}
                </span>
                <p className="mt-1 text-[11px] text-slate-500">
                  {(doc.firmas_origen || []).map((f) => `${f.orden_firma}. ${f.firmante}`).join(' → ') || 'Sin firmas registradas'}
                </p>
              </li>
            ))}
          </ul>
        </section>
      ) : tramite.descuento && (
        <section className="rounded-xl border border-slate-200 bg-white p-4 text-sm">
          <h3 className="mb-2 font-semibold text-slate-800">Descuento del 50% en diplomado</h3>
          <p className="text-xs text-slate-600">{tramite.descuento.nombre_diplomado} · {tramite.descuento.unidad_postgrado}</p>
          <p className="mt-1 text-xs font-medium text-emerald-700">{tramite.descuento.financiamiento?.replaceAll('_', ' ')}</p>
        </section>
      )}

      <ResultadoVerificacion tramiteId={tramiteId} version={recarga} />

      {error && <Alerta mensaje={error} />}
      <div className="flex flex-wrap gap-2">
        <button type="button" disabled={!puedeFirmar || tramite.estado !== 'EN_BANDEJA_DESPACHO'} onClick={() => onFirmar([tramite.id])}
          className="inline-flex flex-1 items-center justify-center gap-2 rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-40">
          <PenTool className="h-4 w-4" />Firmar Digitalmente
        </button>
        <button type="button" onClick={() => setObservando((v) => !v)} disabled={tramite.estado !== 'EN_BANDEJA_DESPACHO'}
          className="inline-flex items-center gap-2 rounded-lg border border-amber-300 px-4 py-2 text-sm font-medium text-amber-800 hover:bg-amber-50 disabled:opacity-40">
          <Undo2 className="h-4 w-4" />Devolver a revisión
        </button>
      </div>
      {observando && <FormObservacion documentos={documentos} onSubmit={devolver} disabled={guardando} />}
    </div>
  );
}
