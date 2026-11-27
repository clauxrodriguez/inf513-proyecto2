import { useState } from 'react';
import { CreditCard, ExternalLink, FileStack, Inbox, PenLine } from 'lucide-react';
import ModalPagoTramite from '../pagos/ModalPagoTramite';
import { abrirArchivo } from '../../api/archivos';
import {
  actualizarRequisito,
  enviarADespacho,
  iniciarRevision,
  obtenerArchivoExpediente,
  registrarObservacion,
  subsanarObservacion,
} from '../../api/cuatroApi';
import { compilarExpediente, eliminarComprobante, registrarComprobante } from '../../api/tramitesApi';
import { useAuth } from '../../context/AuthContext';
import Alerta from '../ui/Alerta';
import ComprobantesCaja from './ComprobantesCaja';
import DocumentosOrigen from './DocumentosOrigen';
import FormObservacion from './FormObservacion';
import FormSubsanacion from './FormSubsanacion';
import { mensajeErrorApi, moneda } from './calculosTramite';

const ESTADOS_ORIGEN = ['SOLICITADO_ORIGEN', 'EN_FIRMA_ORIGEN'];
const ESTADOS_VENTANILLA = ['INGRESADO', 'EN_REVISION', 'OBSERVADO'];

export default function PanelRevision({ tramite, onRecargar }) {
  const { user, tienePermiso } = useAuth();
  const [error, setError] = useState('');
  const [mensaje, setMensaje] = useState('');
  const [guardando, setGuardando] = useState(false);
  const [pagando, setPagando] = useState(false);

  const esEstudiante = user?.rol === 'ESTUDIANTE';
  const esVentanilla = tienePermiso('TRAMITES_RECEPCION');
  const esOrigen = tienePermiso('TRAMITES_ORIGEN');
  const enOrigen = ESTADOS_ORIGEN.includes(tramite.estado);
  const enRevision = tramite.estado === 'EN_REVISION';
  const esLegalizacion = tramite.tipo === 'LEGALIZACION';

  const pendientes = (tramite.observaciones || []).filter((item) => item.estado === 'PENDIENTE');
  const requisitosIncompletos = (tramite.requisitos || []).filter((item) => item.obligatorio && !item.cumplido);
  const pagoInsuficiente = esLegalizacion && Number(tramite.importe_pagado) < Number(tramite.importe_esperado);
  const sinExpedienteVigente = !(tramite.expedientes || []).some((item) => item.vigente);
  const descuentoFueraDePlazo = !esLegalizacion && !tramite.descuento?.dentro_plazo;
  const puedeEnviarADespacho = !pendientes.length && !requisitosIncompletos.length && !pagoInsuficiente
    && !(esLegalizacion && sinExpedienteVigente) && !descuentoFueraDePlazo;
  const saldo = Math.max(0, Number(tramite.importe_esperado) - Number(tramite.importe_pagado));
  const puedePagar = esEstudiante && tienePermiso('PAGOS_REALIZAR') && esLegalizacion && saldo > 0
    && [...ESTADOS_ORIGEN, ...ESTADOS_VENTANILLA].includes(tramite.estado);
  const puedeObservar = (enOrigen && esOrigen) || (enRevision && esVentanilla)
    || (tramite.estado === 'EN_BANDEJA_DESPACHO' && tienePermiso('DESPACHO_FIRMAR'));

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
      setError(mensajeErrorApi(err, 'No se pudo completar la operación.'));
      return false;
    } finally {
      setGuardando(false);
    }
  };

  const abrirExpediente = async (expedienteId) => {
    setError((await abrirArchivo(() => obtenerArchivoExpediente(expedienteId))) || '');
  };

  return (
    <div className="space-y-5">
      {enOrigen && (
        <section className="rounded-xl border border-indigo-200 bg-indigo-50 p-5">
          <h2 className="flex items-center gap-2 font-semibold text-indigo-950"><PenLine className="h-5 w-5" aria-hidden="true" />En emisión y firma en origen</h2>
          <p className="mt-1 text-sm text-indigo-900">
            Las unidades emisoras (Carrera, Decanato, Admisiones, DAGA) preparan y firman digitalmente cada documento.
            El trámite ingresará a la Ventanilla del Vicerrectorado cuando todos completen su cadena de firmas.
          </p>
        </section>
      )}

      {tramite.estado === 'INGRESADO' && (
        <section className="rounded-xl border border-blue-200 bg-blue-50 p-5">
          <h2 className="flex items-center gap-2 font-semibold text-blue-950"><Inbox className="h-5 w-5" aria-hidden="true" />Pendiente de recepción en Ventanilla</h2>
          {esVentanilla ? (
            <>
              <p className="mt-1 text-sm text-blue-900">Los documentos completaron su cadena de firmas en origen. Recíbalo para iniciar el cotejo de requisitos y recibos.</p>
              <button type="button" disabled={guardando} onClick={() => ejecutar(() => iniciarRevision(tramite.id))}
                className="mt-3 rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
                Recibir e iniciar revisión
              </button>
            </>
          ) : <p className="mt-1 text-sm text-blue-900">Su trámite completó las firmas en origen y espera ser recibido por la Unidad de Legalizaciones.</p>}
        </section>
      )}

      <section className="rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-3 text-lg font-semibold text-slate-800">Flujo procesal del trámite</h2>
        <ol className="grid gap-2.5 md:grid-cols-2">
          {(tramite.flujo || []).map((paso) => (
            <li key={paso.orden} className={`rounded-lg border p-3 ${paso.completado ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50'}`}>
              <p className="text-xs font-semibold text-slate-500">Paso {paso.orden} · {paso.unidad}{paso.completado ? ' · Completado' : ' · Pendiente'}</p>
              <p className="font-medium text-slate-800">{paso.paso}</p>
              {paso.descripcion && <p className="mt-0.5 text-xs text-slate-600">{paso.descripcion}</p>}
              {(paso.firma_requerida || paso.sello_requerido) && (
                <p className="mt-1 text-xs font-medium text-slate-700">
                  {[paso.firma_requerida ? `Firma: ${paso.firma_requerida}` : null, paso.sello_requerido ? `Sello: ${paso.sello_requerido}` : null].filter(Boolean).join(' · ')}
                </p>
              )}
            </li>
          ))}
        </ol>
      </section>

      {esLegalizacion && <DocumentosOrigen documentos={tramite.documentos || []} puedeVerificar={esVentanilla || esOrigen} />}

      {tramite.descuento && (
        <section className="rounded-xl border border-slate-200 bg-white p-5 text-sm">
          <h2 className="mb-2 text-lg font-semibold text-slate-800">Solicitud de descuento</h2>
          <p className="text-slate-700">{tramite.descuento.nombre_diplomado} · {tramite.descuento.unidad_postgrado} · Avance N° {tramite.descuento.nro_avance_academico}</p>
          <p className={`mt-1 font-medium ${tramite.descuento.dentro_plazo ? 'text-emerald-700' : 'text-red-700'}`}>
            Última nota {tramite.descuento.fecha_ultima_nota} · Plazo hasta {tramite.descuento.fecha_limite} · {tramite.descuento.financiamiento?.replaceAll('_', ' ')}
          </p>
        </section>
      )}

      {puedePagar && (
        <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
          <div>
            <h2 className="font-semibold text-emerald-900">Pago del arancel pendiente</h2>
            <p className="text-sm text-emerald-800">Saldo a pagar al contado: <span className="font-bold">{moneda(saldo)}</span>. Puede pagarlo en línea con tarjeta.</p>
          </div>
          <button type="button" onClick={() => setPagando(true)}
            className="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800">
            <CreditCard className="h-4 w-4" aria-hidden="true" />Pagar en línea
          </button>
        </section>
      )}
      {pagando && (
        <ModalPagoTramite tramite={tramite} onCerrar={() => setPagando(false)}
          onPagado={async (texto) => { setPagando(false); setError(''); setMensaje(texto); await onRecargar(); }} />
      )}

      {(!enOrigen || tramite.comprobantes?.length > 0) && (
        <ComprobantesCaja tramite={tramite} disabled={guardando}
          editable={esVentanilla && ESTADOS_VENTANILLA.includes(tramite.estado)}
          onRegistrar={(datos) => ejecutar(() => registrarComprobante(tramite.id, datos))}
          onEliminar={(id) => ejecutar(() => eliminarComprobante(id))} />
      )}

      {!enOrigen && (
        <section className="rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-3 text-lg font-semibold text-slate-800">Requisitos</h2>
          <div className="space-y-2">
            {(tramite.requisitos || []).map((requisito) => (
              <label key={requisito.requisito_id} className="flex items-start gap-3 rounded-lg border border-slate-100 p-3 text-sm">
                <input type="checkbox" checked={Boolean(requisito.cumplido)} disabled={!esVentanilla || !enRevision || guardando}
                  onChange={(event) => ejecutar(() => actualizarRequisito(tramite.id, requisito.requisito_id, event.target.checked))}
                  className="mt-1 h-4 w-4 accent-blue-900" />
                <span className="flex-1 text-slate-700">{requisito.descripcion}
                  <span className="ml-2 text-xs text-slate-500">{requisito.obligatorio ? 'Obligatorio' : 'Opcional'}</span>
                </span>
                {requisito.cumplido && <span className="text-xs font-medium text-emerald-700">Verificado</span>}
              </label>
            ))}
          </div>
        </section>
      )}

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
              {(esEstudiante || esVentanilla || esOrigen) && observacion.estado === 'PENDIENTE' && tramite.estado === 'OBSERVADO' && (
                <FormSubsanacion
                  disabled={guardando}
                  conRespaldo={!esLegalizacion && Boolean(tramite.ingreso_a_ventanilla)}
                  documentoObservado={observacion.documento_nombre}
                  onSubmit={(body) => ejecutar(() => subsanarObservacion(observacion.id, body))}
                />
              )}
            </article>
          ))}
          {!tramite.observaciones?.length && <p className="text-sm text-slate-500">No hay observaciones registradas.</p>}
        </div>
        {pendientes.length > 0 && tramite.estado === 'OBSERVADO' && (
          <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            El SLA está detenido. Quedan {pendientes.length} observación(es) pendiente(s) de subsanación.
          </p>
        )}
      </section>

      {!enOrigen && (
        <section className="rounded-xl border border-slate-200 bg-white p-5">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-lg font-semibold text-slate-800">Expediente digital</h2>
            {esVentanilla && enRevision && (
              <button type="button" disabled={guardando} onClick={() => ejecutar(() => compilarExpediente(tramite.id))}
                className="inline-flex items-center gap-2 rounded-lg bg-blue-900 px-3 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
                <FileStack className="h-4 w-4" aria-hidden="true" />{sinExpedienteVigente ? 'Compilar expediente' : 'Recompilar (nueva versión)'}
              </button>
            )}
          </div>
          <div className="space-y-2">
            {(tramite.expedientes || []).map((expediente) => (
              <button type="button" key={expediente.id} onClick={() => abrirExpediente(expediente.id)}
                className="flex w-full flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-3 text-left text-sm hover:bg-slate-50">
                <span className="text-blue-800"><ExternalLink className="mr-1 inline h-4 w-4" aria-hidden="true" />Versión {expediente.version}</span>
                <span className="text-slate-500">{expediente.vigente ? 'Vigente' : 'Histórica'} · {expediente.total_fojas} fojas · SHA-256 {expediente.hash_sha256.slice(0, 12)}…</span>
              </button>
            ))}
            {!tramite.expedientes?.length && <p className="text-sm text-slate-500">Aún no se compiló el expediente.</p>}
          </div>
        </section>
      )}

      {tramite.estado === 'FIRMADO_DIGITALMENTE' && (
        <section className="rounded-xl border border-blue-200 bg-blue-50 p-5 text-sm text-blue-900">
          Firmado digitalmente por el Vicerrectorado: la Ventanilla emitirá el certificado oficial con QR y el trámite concluirá.
        </section>
      )}

      {puedeObservar && (
        <FormObservacion documentos={tramite.documentos || []} disabled={guardando}
          onSubmit={(payload) => ejecutar(() => registrarObservacion(tramite.id, payload))} />
      )}

      {esVentanilla && enRevision && (
        <div className="space-y-3">
          <button type="button" disabled={guardando || !puedeEnviarADespacho} onClick={() => ejecutar(() => enviarADespacho(tramite.id))}
            className="w-full rounded-lg bg-emerald-700 px-4 py-3 font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50">
            Confirmar revisión y derivar a despacho
          </button>
          {!puedeEnviarADespacho && (
            <ul className="list-disc space-y-1 rounded-lg border border-amber-200 bg-amber-50 p-4 pl-8 text-sm text-amber-800">
              {pendientes.length > 0 && <li>Hay observaciones pendientes.</li>}
              {requisitosIncompletos.length > 0 && <li>Faltan {requisitosIncompletos.length} requisito(s) obligatorio(s) por verificar.</li>}
              {pagoInsuficiente && <li>Los recibos de legalización no cubren el importe esperado.</li>}
              {esLegalizacion && sinExpedienteVigente && <li>Compile el expediente digital.</li>}
              {descuentoFueraDePlazo && <li>La solicitud de descuento está fuera de plazo (sin financiamiento).</li>}
            </ul>
          )}
        </div>
      )}

      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />
    </div>
  );
}
