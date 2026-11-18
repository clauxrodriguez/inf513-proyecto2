import { useState } from 'react';
import { CheckCircle2, ExternalLink, Inbox } from 'lucide-react';
import Alerta from '../ui/Alerta';
import { abrirArchivo } from '../../api/archivos';
import {
  actualizarRequisito,
  enviarADespacho,
  enrutarPosteriorFirma,
  iniciarRevision,
  obtenerArchivoDocumento,
  obtenerArchivoExpediente,
  registrarObservacion,
  subsanarObservacion,
} from '../../api/cuatroApi';
import { validarDocumento } from '../../api/tramitesApi';
import FormObservacion from './FormObservacion';
import FormSubsanacion from './FormSubsanacion';
import ModalValidarDocumento from './ModalValidarDocumento';

const moneda = (valor) => `${Number(valor || 0).toFixed(2)} Bs`;

const ROLES_REVISION = ['RECEPCION', 'VICERRECTOR', 'SUPLENTE', 'RECTORADO', 'ADMINISTRADOR'];

export default function PanelRevision({ tramite, user, onRecargar }) {
  const [error, setError] = useState('');
  const [documentoAValidar, setDocumentoAValidar] = useState(null);
  const [mensaje, setMensaje] = useState('');
  const [guardando, setGuardando] = useState(false);
  const esRecepcion = user?.rol === 'RECEPCION';
  const esEstudiante = user?.rol === 'ESTUDIANTE';
  const puedeEnrutar = ['VICERRECTOR', 'SUPLENTE'].includes(user?.rol);
  const puedeRevisar = ROLES_REVISION.includes(user?.rol);
  const pendientes = (tramite.observaciones || []).filter((item) => item.estado === 'PENDIENTE');
  const requisitosIncompletos = (tramite.requisitos || []).filter((item) => item.obligatorio && !item.cumplido);
  const documentosActivos = (tramite.documentos || []).filter((item) => item.estado !== 'RECHAZADO');
  const documentosIncompletos = tramite.tipo === 'LEGALIZACION' && (
    !documentosActivos.length || documentosActivos.some((item) => (
      !item.firmas_verificadas
      || !item.nro_valorado
      || Number(item.importe_valorado || 0) < Number(item.arancel_esperado || 0)
    ))
  );
  const descuentoSinFinanciamiento = tramite.tipo === 'DESCUENTO_DIPLOMADO'
    && tramite.descuento?.financiamiento !== 'CON_FINANCIAMIENTO';
  const sinExpedienteVigente = !(tramite.expedientes || []).some((item) => item.vigente);
  const puedeEnviarADespacho = !pendientes.length && !requisitosIncompletos.length
    && !documentosIncompletos && !descuentoSinFinanciamiento && !sinExpedienteVigente;

  const ejecutar = async (accion) => {
    setError('');
    setMensaje('');
    setGuardando(true);
    try {
      const resultado = await accion();
      setMensaje(resultado.data?.message || 'Cambios guardados.');
      await onRecargar();
      return true;
    } catch (err) {
      setError(err.response?.data?.message || 'No se pudo completar la operación.');
      return false;
    } finally {
      setGuardando(false);
    }
  };

  const abrir = async (obtener) => {
    setError('');
    const fallo = await abrirArchivo(obtener);
    if (fallo) setError(fallo);
  };
  const validar = async (documentoId, payload) => {
    try {
      const resultado = await validarDocumento(documentoId, payload);
      setMensaje(resultado.data?.message || 'Documento validado.');
      await onRecargar();
      return true;
    } catch (err) {
      return err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat().join(' ')
        : err.response?.data?.message || 'No se pudo validar el documento.';
    }
  };
  const puedeValidarDocumentos = esRecepcion && ['INGRESADO', 'EN_REVISION'].includes(tramite.estado);

  return (
    <div className="space-y-5">
      {tramite.estado === 'INGRESADO' && (
        <section className="rounded-xl border border-blue-200 bg-blue-50 p-5">
          <h2 className="flex items-center gap-2 font-semibold text-blue-950"><Inbox className="h-5 w-5" />Solicitud en línea pendiente de recepción</h2>
          {esRecepcion ? (
            <>
              <p className="mt-1 text-sm text-blue-900">
                El estudiante registró este trámite desde el sistema. Recíbalo para iniciar la revisión de requisitos
                {tramite.tipo === 'LEGALIZACION' ? ' (también inicia al validar el primer documento).' : '.'}
              </p>
              <button type="button" disabled={guardando} onClick={() => ejecutar(() => iniciarRevision(tramite.id))}
                className="mt-3 rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
                Recibir e iniciar revisión
              </button>
            </>
          ) : (
            <p className="mt-1 text-sm text-blue-900">Su solicitud fue registrada y espera ser recibida por la Unidad de Legalizaciones.</p>
          )}
        </section>
      )}
      <section className="rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-3 text-lg font-semibold text-slate-800">Flujo procesal</h2>
        <p className="mb-3 text-sm text-slate-600">
          Ruta {tramite.destino}{tramite.destino === 'EXTERIOR' && tramite.pais_destino ? ` · ${tramite.pais_destino}` : ''}.
          {tramite.destino === 'EXTERIOR'
            ? ' Tras la firma del Vicerrectorado, el trámite requiere derivación a Rectorado.'
            : ' La ruta nacional concluye tras la firma del Vicerrectorado.'}
        </p>
        <ol className="grid gap-2 md:grid-cols-2">
          {(tramite.flujo || []).map((paso) => (
            <li key={`${paso.orden}-${paso.unidad}`} className={`rounded-lg border p-3 ${paso.completado ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50'}`}>
              <p className="text-xs font-semibold text-slate-500">Paso {paso.orden} · {paso.unidad}</p>
              <p className="font-medium text-slate-800">{paso.paso}</p>
              <p className="text-xs text-slate-600">
                {paso.firma_requerida ? 'Firma requerida' : 'Sin firma requerida'}
                {paso.sello_requerido ? ' · Sello requerido' : ''}
                {paso.completado ? ' · Completado' : ''}
              </p>
            </li>
          ))}
          {!tramite.flujo?.length && <li className="text-sm text-slate-500">El esquema no define pasos para esta ruta.</li>}
        </ol>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-3 text-lg font-semibold text-slate-800">Documentos y firmas precedentes</h2>
        <div className="space-y-3">
          {(tramite.documentos || []).map((documento) => (
            <article key={documento.id} className="rounded-lg border border-slate-200 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <h3 className="font-semibold text-slate-800">{documento.codigo} · {documento.documento_nombre}</h3>
                  <p className="text-xs text-slate-500">
                    Firmas/sellos: {documento.firmas_verificadas ? 'verificados' : 'pendientes'} · Valorado: {documento.nro_valorado
                      ? `${documento.nro_valorado} (${moneda(documento.importe_valorado)} de ${moneda(documento.arancel_esperado)})`
                      : `sin registrar (arancel ${moneda(documento.arancel_esperado)})`} · Estado: {documento.estado}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                  {documento.archivo_ruta && <button type="button" onClick={() => abrir(() => obtenerArchivoDocumento(documento.id))} className="inline-flex items-center gap-1 text-sm text-blue-800 hover:underline">
                    <ExternalLink className="h-4 w-4" /> Revisar documento
                  </button>}
                  {puedeValidarDocumentos && documento.estado !== 'RECHAZADO' && (
                    <button type="button" onClick={() => setDocumentoAValidar(documento)}
                      className={`inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium ${documento.estado === 'VALIDO' ? 'border border-slate-300 text-slate-700 hover:bg-slate-50' : 'bg-blue-900 text-white hover:bg-blue-800'}`}>
                      <CheckCircle2 className="h-3.5 w-3.5" />{documento.estado === 'VALIDO' ? 'Corregir valorado' : 'Validar firmas y valorado'}
                    </button>
                  )}
                </div>
              </div>
              {documento.firmas_precedentes?.length
                ? <ol className="mt-3 flex flex-wrap gap-2">{documento.firmas_precedentes.map((firma) => (
                  <li key={`${firma.orden}-${firma.unidad}`} className="rounded-full bg-blue-50 px-3 py-1 text-xs text-blue-900">
                    {firma.orden}. {firma.unidad} · {firma.cargo_firmante}{firma.requiere_sello ? ' · Sello' : ''}
                  </li>
                ))}</ol>
                : <p className="mt-2 text-xs text-slate-500">No hay firmas precedentes configuradas para este documento.</p>}
            </article>
          ))}
          {!tramite.documentos?.length && <p className="text-sm text-slate-500">Este trámite no tiene documentos del catálogo.</p>}
        </div>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-3 text-lg font-semibold text-slate-800">Requisitos obligatorios</h2>
        <div className="space-y-2">
          {(tramite.requisitos || []).map((requisito) => (
            <label key={requisito.requisito_id} className="flex items-start gap-3 rounded-lg border border-slate-100 p-3 text-sm">
              <input type="checkbox" checked={Boolean(requisito.cumplido)} disabled={!esRecepcion || tramite.estado !== 'EN_REVISION' || guardando}
                onChange={(event) => ejecutar(() => actualizarRequisito(tramite.id, requisito.requisito_id, event.target.checked))}
                className="mt-1 h-4 w-4 accent-blue-900" />
              <span className="flex-1 text-slate-700">{requisito.descripcion}
                <span className="ml-2 text-xs text-slate-500">{requisito.obligatorio ? 'Obligatorio' : 'Opcional'}{requisito.pais_aplica ? ` · ${requisito.pais_aplica}` : ''}</span>
              </span>
              {requisito.cumplido && <span className="text-xs font-medium text-emerald-700">Verificado</span>}
            </label>
          ))}
          {!tramite.requisitos?.length && <p className="text-sm text-slate-500">No hay requisitos asignados.</p>}
        </div>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-3 text-lg font-semibold text-slate-800">Observaciones</h2>
        <div className="space-y-3">
          {(tramite.observaciones || []).map((observacion) => (
            <article key={observacion.id} className="rounded-lg border border-slate-200 p-4">
              <div className="flex flex-wrap justify-between gap-2">
                <p className="font-medium text-slate-800">{observacion.tipo.replaceAll('_', ' ')}</p>
                <span className={`rounded-full px-2 py-1 text-xs font-medium ${observacion.estado === 'PENDIENTE' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800'}`}>
                  {observacion.estado}
                </span>
              </div>
              <p className="mt-1 text-sm text-slate-700">{observacion.descripcion}</p>
              <p className="mt-1 text-xs text-slate-500">
                {observacion.documento_nombre ? `Documento: ${observacion.documento_nombre} · ` : ''}
                {observacion.registrado_por_nombre || 'Sistema'} · {observacion.fecha_registro}
                {observacion.fecha_subsanacion ? ` · Subsanada: ${observacion.fecha_subsanacion}` : ''}
              </p>
              {(esEstudiante || esRecepcion) && observacion.estado === 'PENDIENTE' && tramite.estado === 'OBSERVADO'
                && <FormSubsanacion disabled={guardando} onSubmit={(body) => ejecutar(() => subsanarObservacion(observacion.id, body))} />}
            </article>
          ))}
          {!tramite.observaciones?.length && <p className="text-sm text-slate-500">No hay observaciones registradas.</p>}
        </div>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-3 text-lg font-semibold text-slate-800">Versiones del expediente</h2>
        <div className="space-y-2">
          {(tramite.expedientes || []).map((expediente) => (
            <button type="button" key={expediente.id} onClick={() => abrir(() => obtenerArchivoExpediente(expediente.id))}
              className="flex w-full flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-3 text-left text-sm hover:bg-slate-50">
              <span className="text-blue-800"><ExternalLink className="mr-1 inline h-4 w-4" />Versión {expediente.version}</span>
              <span className="text-slate-500">{expediente.vigente ? 'Vigente' : 'Histórica'} · {expediente.total_fojas} fojas</span>
            </button>
          ))}
          {!tramite.expedientes?.length && <p className="text-sm text-slate-500">No hay versiones de expediente.</p>}
        </div>
      </section>

      {puedeEnrutar && tramite.estado === 'FIRMADO_DIGITALMENTE' && (
        <section className="rounded-xl border border-blue-200 bg-blue-50 p-5">
          <h2 className="font-semibold text-blue-950">Continuar ruta posterior a la firma</h2>
          <p className="mt-1 text-sm text-blue-900">
            {tramite.destino === 'EXTERIOR'
              ? 'El sistema derivará este expediente a Rectorado para el sello institucional final.'
              : tramite.certificado_vigente
                ? 'El certificado oficial con QR está vigente; la ruta nacional puede concluir.'
                : 'La ruta nacional espera la emisión del certificado oficial con QR.'}
          </p>
          {(tramite.destino === 'EXTERIOR' || tramite.certificado_vigente) && <button type="button" disabled={guardando}
            onClick={() => ejecutar(() => enrutarPosteriorFirma(tramite.id))}
            className="mt-3 rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
            {tramite.destino === 'EXTERIOR' ? 'Derivar a Rectorado' : 'Concluir ruta nacional'}
          </button>}
        </section>
      )}

      {puedeRevisar && tramite.estado === 'EN_REVISION' && (
        <div className="space-y-3">
          <FormObservacion documentos={tramite.documentos || []} disabled={guardando}
            onSubmit={(payload) => ejecutar(() => registrarObservacion(tramite.id, payload))} />
          {esRecepcion && <button type="button" disabled={guardando || !puedeEnviarADespacho}
            onClick={() => ejecutar(() => enviarADespacho(tramite.id))}
            className="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50">
            Confirmar revisión y derivar a despacho
          </button>}
          {esRecepcion && !puedeEnviarADespacho && <ul className="list-disc space-y-1 rounded-lg border border-amber-200 bg-amber-50 p-4 pl-8 text-sm text-amber-800">
            {pendientes.length > 0 && <li>Hay observaciones pendientes.</li>}
            {requisitosIncompletos.length > 0 && <li>Faltan {requisitosIncompletos.length} requisito(s) obligatorio(s) por verificar.</li>}
            {documentosIncompletos && <li>Verifique las firmas precedentes y que cada valorado cubra el arancel esperado.</li>}
            {descuentoSinFinanciamiento && <li>El descuento no tiene estado CON_FINANCIAMIENTO.</li>}
            {sinExpedienteVigente && <li>No hay una versión vigente del expediente.</li>}
          </ul>}
        </div>
      )}

      <div className="space-y-2">
        <Alerta tipo="exito" mensaje={mensaje} />
        <Alerta mensaje={error} />
        {documentoAValidar && <ModalValidarDocumento documento={documentoAValidar} onConfirmar={validar} onCerrar={() => setDocumentoAValidar(null)} />}
        {pendientes.length > 0 && tramite.estado === 'OBSERVADO' && (
          <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            El SLA está detenido. Quedan {pendientes.length} observación(es) pendientes de subsanación.
          </p>
        )}
      </div>
    </div>
  );
}
