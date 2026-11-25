import { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ExternalLink, FileUp, PenTool, ShieldAlert } from 'lucide-react';
import { abrirArchivo } from '../api/archivos';
import { obtenerArchivoDocumento } from '../api/cuatroApi';
import { emitirDocumento, firmarEnOrigen, obtenerBandejaOrigen } from '../api/origenApi';
import ModalFirmaJacobitus from '../components/firma/ModalFirmaJacobitus';
import { mensajeErrorApi } from '../components/tramites/calculosTramite';
import Alerta from '../components/ui/Alerta';

const MAXIMO_PDF = 10 * 1024 * 1024;

/** Botón que carga el PDF emitido por la unidad (primera firma de la cadena). */
function BotonEmitir({ documento, onEmitido, onError }) {
  const entrada = useRef(null);
  const [subiendo, setSubiendo] = useState(false);

  const subir = async (archivo) => {
    if (!archivo) return;
    if (archivo.type !== 'application/pdf') { onError('El documento emitido debe ser un archivo PDF.'); return; }
    if (archivo.size > MAXIMO_PDF) { onError('El PDF no puede superar los 10 MB.'); return; }
    setSubiendo(true);
    try {
      const res = await emitirDocumento(documento.id, archivo);
      onEmitido(res.data.message);
    } catch (err) {
      onError(mensajeErrorApi(err, 'No se pudo cargar el documento.'));
    } finally {
      setSubiendo(false);
      if (entrada.current) entrada.current.value = '';
    }
  };

  return (
    <>
      <input ref={entrada} type="file" accept="application/pdf" className="sr-only" aria-label={`PDF de ${documento.documento_nombre}`}
        onChange={(e) => subir(e.target.files?.[0])} />
      <button type="button" disabled={subiendo} onClick={() => entrada.current?.click()}
        className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs text-slate-700 hover:bg-slate-50 disabled:opacity-50">
        <FileUp className="h-3.5 w-3.5" aria-hidden="true" />{subiendo ? 'Cargando...' : documento.archivo_ruta ? 'Reemplazar PDF' : 'Emitir (cargar PDF)'}
      </button>
    </>
  );
}

/**
 * Bandeja facultativa: documentos cuya siguiente firma de la cadena (catalogo_firma_orden)
 * corresponde a la unidad del usuario. La unidad emisora carga el PDF; cada autoridad firma en su turno.
 */
export default function BandejaOrigen() {
  const [datos, setDatos] = useState({ habilitacion: null, documentos: [] });
  const [seleccion, setSeleccion] = useState([]);
  const [aFirmar, setAFirmar] = useState(null);
  const [mensaje, setMensaje] = useState('');
  const [error, setError] = useState('');
  const [cargando, setCargando] = useState(true);

  const cargar = useCallback(async () => {
    try {
      const res = await obtenerBandejaOrigen();
      setDatos(res.data);
      setSeleccion((previa) => previa.filter((id) => res.data.documentos.some((doc) => doc.id === id && doc.archivo_ruta)));
    } catch (err) {
      setError(mensajeErrorApi(err, 'No se pudo cargar la bandeja facultativa.'));
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const avisar = async (texto) => { setError(''); setMensaje(texto); await cargar(); };
  const alternar = (id) => setSeleccion((previa) => (previa.includes(id) ? previa.filter((x) => x !== id) : [...previa, id]));
  const firmables = datos.documentos.filter((doc) => doc.archivo_ruta);
  const habilitacion = datos.habilitacion;
  // Sin certificado registrado también se abre el modal: permite registrarlo desde Jacobitus
  const puedeFirmar = Boolean(habilitacion?.puede_firmar || habilitacion?.sin_certificado);

  const abrir = async (documentoId) => {
    setError((await abrirArchivo(() => obtenerArchivoDocumento(documentoId))) || '');
  };

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-800">Bandeja Facultativa</h1>
          <p className="text-sm text-slate-500">Emisión y firma digital en origen de los documentos que esperan a su unidad.</p>
        </div>
        <button type="button" disabled={!seleccion.length || !puedeFirmar}
          onClick={() => setAFirmar(datos.documentos.filter((doc) => seleccion.includes(doc.id)))}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
          <PenTool className="h-4 w-4" aria-hidden="true" />Firmar seleccionados ({seleccion.length})
        </button>
      </header>

      {habilitacion && !habilitacion.puede_firmar && (
        <p role="alert" className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          <ShieldAlert className="h-4 w-4 shrink-0" aria-hidden="true" />{habilitacion.motivo}
        </p>
      )}
      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />

      {cargando ? <p className="text-sm text-slate-500">Cargando documentos...</p> : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-600">
              <tr>
                <th className="px-4 py-3">
                  <span className="sr-only">Seleccionar</span>
                  <input type="checkbox" aria-label="Seleccionar todos los documentos listos para firmar"
                    checked={firmables.length > 0 && seleccion.length === firmables.length}
                    onChange={(e) => setSeleccion(e.target.checked ? firmables.map((doc) => doc.id) : [])} />
                </th>
                <th className="px-4 py-3">Trámite / Estudiante</th>
                <th className="px-4 py-3">Documento</th>
                <th className="px-4 py-3">Cadena de firmas</th>
                <th className="px-4 py-3">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {datos.documentos.map((doc) => (
                <tr key={doc.id} className="align-top hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <input type="checkbox" disabled={!doc.archivo_ruta} checked={seleccion.includes(doc.id)} onChange={() => alternar(doc.id)}
                      aria-label={`Seleccionar ${doc.documento_nombre} de ${doc.tramite_codigo}`} />
                  </td>
                  <td className="px-4 py-3 min-w-[200px]">
                    <Link to={`/tramites/${doc.tramite_id}`} className="font-semibold text-blue-900 hover:underline">{doc.tramite_codigo}</Link>
                    <p className="text-slate-800">{doc.estudiante}</p>
                    <p className="text-xs text-slate-500">C.I. {doc.ci} · {doc.carrera}</p>
                    {doc.prioridad !== 'NORMAL' && <span className="mt-1 inline-block rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700">{doc.prioridad}</span>}
                  </td>
                  <td className="px-4 py-3 min-w-[200px]">
                    <p className="font-medium text-slate-800">{doc.documento_nombre}</p>
                    <p className="text-xs text-slate-500">{doc.cantidad_paginas} página(s){doc.cantidad_materias ? ` · ${doc.cantidad_materias} materia(s)` : ''}</p>
                    {doc.archivo_ruta
                      ? <button type="button" onClick={() => abrir(doc.id)} className="mt-1 inline-flex items-center gap-1 text-xs text-blue-800 hover:underline">
                        <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />Ver PDF
                      </button>
                      : <p className="mt-1 text-xs text-amber-700">Pendiente de emisión</p>}
                  </td>
                  <td className="px-4 py-3 min-w-[220px]">
                    <p className="text-xs font-medium text-slate-700">Le corresponde la firma {doc.orden_pendiente} de {doc.total_firmas}: {doc.cargo_firmante}</p>
                    <ol className="mt-1 space-y-0.5 text-xs text-slate-500">
                      {doc.firmas.map((firma) => <li key={firma.orden_firma}>✓ {firma.orden_firma}. {firma.firmante} · {firma.fecha_firma}</li>)}
                    </ol>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-col items-start gap-2">
                      {doc.es_emisor && <BotonEmitir documento={doc} onEmitido={avisar} onError={(texto) => { setMensaje(''); setError(texto); }} />}
                      <button type="button" disabled={!doc.archivo_ruta || !puedeFirmar} onClick={() => setAFirmar([doc])}
                        className="inline-flex items-center gap-1 rounded-lg bg-blue-900 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-blue-800 disabled:opacity-50">
                        <PenTool className="h-3.5 w-3.5" aria-hidden="true" />Firmar
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
              {!datos.documentos.length && <tr><td colSpan="5" className="px-4 py-8 text-center text-sm text-slate-500">No hay documentos esperando la firma de su unidad.</td></tr>}
            </tbody>
          </table>
        </div>
      )}

      {aFirmar && habilitacion && (
        <ModalFirmaJacobitus etapa="ORIGEN" habilitacion={habilitacion}
          documentos={aFirmar.map((doc) => ({ id: doc.id, etiqueta: `${doc.tramite_codigo} · ${doc.documento_nombre} (firma ${doc.orden_pendiente}/${doc.total_firmas})` }))}
          firmarSimulado={(datos) => firmarEnOrigen({ documentos: aFirmar.map((doc) => doc.id), ...datos })}
          onCerrar={() => setAFirmar(null)}
          onFirmado={async (texto) => { setAFirmar(null); setSeleccion([]); await avisar(texto); }} />
      )}
    </div>
  );
}
