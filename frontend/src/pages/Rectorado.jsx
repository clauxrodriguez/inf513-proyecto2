import { useCallback, useEffect, useState } from 'react';
import { Eye, Globe, PenTool, RefreshCw } from 'lucide-react';
import ModalFirmaJacobitus from '../components/firma/ModalFirmaJacobitus';
import { firmarRectorado, mensajeError, obtenerBandejaRectorado } from '../api/despachoApi';
import { InsigniaCalidad, InsigniaPrioridad, InsigniaSemaforo } from '../components/despacho/Insignias';
import PanelHabilitacion from '../components/despacho/PanelHabilitacion';
import ResultadoVerificacion from '../components/despacho/ResultadoVerificacion';
import VisorPdf from '../components/despacho/VisorPdf';
import Alerta from '../components/ui/Alerta';

/** Trámites al exterior: firma digital institucional final del Rector/a, previa a la Apostilla en Cancillería. */
export default function Rectorado() {
  const [datos, setDatos] = useState({ habilitacion: null, expedientes: [] });
  const [cargando, setCargando] = useState(true);
  const [activo, setActivo] = useState(null);
  const [version, setVersion] = useState(0);
  const [firmando, setFirmando] = useState(false);
  const [mensaje, setMensaje] = useState('');
  const [error, setError] = useState('');

  const cargar = useCallback(async () => {
    try {
      const res = await obtenerBandejaRectorado();
      setDatos(res.data);
    } catch (err) {
      setError(mensajeError(err, 'No se pudo cargar la bandeja de Rectorado.'));
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const seleccionado = datos.expedientes.find((t) => t.id === activo);

  const puedeFirmar = Boolean(datos.habilitacion?.puede_firmar || datos.habilitacion?.sin_certificado);
  const alFirmar = async (texto) => {
    setFirmando(false);
    setError('');
    setMensaje(texto);
    setVersion((v) => v + 1);
    await cargar();
  };

  if (cargando) return <p className="text-sm text-slate-500">Cargando bandeja de Rectorado...</p>;

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-bold text-slate-800"><Globe className="h-5 w-5 text-blue-900" aria-hidden="true" />Visado Rectorado · Exterior</h1>
          <p className="text-sm text-slate-500">Expedientes firmados por el Vicerrectorado con destino al exterior · firma institucional previa a la Apostilla</p>
        </div>
        <button type="button" onClick={cargar} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-white">
          <RefreshCw className="h-4 w-4" aria-hidden="true" />Actualizar
        </button>
      </header>

      <PanelHabilitacion habilitacion={datos.habilitacion} />
      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />

      {!datos.expedientes.length ? (
        <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">No hay expedientes derivados a Rectorado.</div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-600">
              <tr>
                <th className="px-3 py-2.5">Expediente</th><th className="px-3 py-2.5">Solicitante</th><th className="px-3 py-2.5">País / Institución</th>
                <th className="px-3 py-2.5">Firma Vicerrectorado</th><th className="px-3 py-2.5">SLA</th><th className="px-3 py-2.5">Rectorado</th><th className="px-3 py-2.5 text-right">Acción</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {datos.expedientes.map((t) => (
                <tr key={t.id} className={activo === t.id ? 'bg-blue-50/70' : 'hover:bg-slate-50'}>
                  <td className="px-3 py-2.5"><div className="font-semibold text-slate-800">{t.codigo}</div><InsigniaPrioridad prioridad={t.prioridad} /></td>
                  <td className="px-3 py-2.5"><div>{t.solicitante}</div><div className="text-xs text-slate-500">{t.carrera}</div></td>
                  <td className="px-3 py-2.5"><div>{t.pais_destino}</div><div className="text-xs text-slate-500">{t.institucion_destino || '—'}</div></td>
                  <td className="px-3 py-2.5">
                    {t.firma_despacho ? (
                      <>
                        <div className="flex items-center gap-1.5">{t.firma_despacho.firmante} <InsigniaCalidad calidad={t.firma_despacho.delegacion_id ? 'INTERINO' : 'TITULAR'} /></div>
                        <div className="text-xs text-slate-500">{new Date(t.firma_despacho.fecha_firma).toLocaleString('es-BO')}{t.firma_despacho.es_remota ? ' · remota' : ''}</div>
                      </>
                    ) : <span className="text-xs text-red-600">Sin firma</span>}
                  </td>
                  <td className="px-3 py-2.5"><InsigniaSemaforo semaforo={t.semaforo} horas={t.horas_consumidas} plazo={t.plazo_horas} /></td>
                  <td className="px-3 py-2.5">
                    {t.firmado_rectorado
                      ? <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Firmado · pendiente de certificado QR</span>
                      : <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">Pendiente de firma</span>}
                  </td>
                  <td className="px-3 py-2.5 text-right">
                    <button type="button" onClick={() => { setActivo(t.id); setMensaje(''); setError(''); }}
                      className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-white">
                      <Eye className="h-3.5 w-3.5" aria-hidden="true" />Revisar
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {seleccionado && (
        <section className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
          <VisorPdf tramiteId={seleccionado.id} version={version} />
          <div className="space-y-4">
            <ResultadoVerificacion tramiteId={seleccionado.id} version={version} />
            {!seleccionado.firmado_rectorado ? (
              <div className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
                <h3 className="font-semibold text-slate-800">Firma institucional del Rectorado</h3>
                <p className="text-xs text-slate-500">La firma se agrega como co-firma PAdES: la del Vicerrectorado se conserva intacta.</p>
                <button type="button" onClick={() => setFirmando(true)} disabled={!puedeFirmar}
                  className="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-900 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-50">
                  <PenTool className="h-4 w-4" aria-hidden="true" />Firmar digitalmente
                </button>
              </div>
            ) : (
              <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                Firmado por {seleccionado.firma_rectorado?.firmante} el {new Date(seleccionado.firma_rectorado?.fecha_firma).toLocaleString('es-BO')}.
                La Ventanilla ya puede emitir el certificado oficial con QR.
              </div>
            )}
          </div>
        </section>
      )}

      {firmando && seleccionado && (
        <ModalFirmaJacobitus etapa="RECTORADO" habilitacion={datos.habilitacion} permitirRemota={false}
          documentos={[{ id: seleccionado.id, etiqueta: `${seleccionado.codigo} · expediente al exterior (${seleccionado.pais_destino})` }]}
          firmarSimulado={({ pin }) => firmarRectorado(seleccionado.id, pin)}
          onCerrar={() => setFirmando(false)} onFirmado={alFirmar} />
      )}
    </div>
  );
}
