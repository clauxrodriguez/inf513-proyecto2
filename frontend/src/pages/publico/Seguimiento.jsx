import { useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, CheckCircle2, Circle, Download, Search, ShieldCheck } from 'lucide-react';
import { abrirArchivo } from '../../api/archivos';
import { mensajeError } from '../../api/despachoApi';
import { consultarSeguimiento, descargarCertificadoPublico } from '../../api/publicoApi';
import LayoutPublico from '../../components/publico/LayoutPublico';
import Alerta from '../../components/ui/Alerta';

const COLOR_SLA = { ROJO: 'bg-red-500', AMARILLO: 'bg-amber-400', VERDE: 'bg-emerald-500' };

function AvanceSla({ tramite }) {
  const sla = tramite.sla;
  if (!sla) return null;
  if (sla.es_final) return <p className="text-xs text-slate-500">Plazo de atención cerrado: el trámite concluyó.</p>;
  const porcentaje = Math.min(100, Number(sla.porcentaje || 0));
  return (
    <div>
      <div className="flex justify-between text-xs text-slate-600">
        <span>Plazo de atención (72 horas hábiles){sla.estado === 'OBSERVADO' ? ' · detenido mientras subsana' : ''}</span>
        <span>{Number(sla.horas_consumidas || 0).toFixed(1)} / {sla.plazo_horas} h</span>
      </div>
      <div className="mt-1 h-2 rounded-full bg-slate-200">
        <div className={`h-2 rounded-full ${COLOR_SLA[sla.semaforo] || COLOR_SLA.VERDE}`} style={{ width: `${porcentaje}%` }} />
      </div>
    </div>
  );
}

function TarjetaTramite({ tramite }) {
  const [error, setError] = useState('');
  const descargar = async () => {
    setError((await abrirArchivo(() => descargarCertificadoPublico(tramite.codigo_verificacion))) || '');
  };

  return (
    <article className="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="font-bold text-slate-800">{tramite.codigo}</h2>
          <p className="text-sm text-slate-500">{tramite.tipo?.replaceAll('_', ' ')} · {tramite.destino}{tramite.pais_destino ? ` · ${tramite.pais_destino}` : ''}</p>
        </div>
        <span className="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-900">{tramite.estado}</span>
      </div>

      <dl className="grid gap-3 text-sm sm:grid-cols-3">
        <div><dt className="text-xs text-slate-500">Ingreso</dt><dd className="text-slate-800">{new Date(tramite.fecha_ingreso).toLocaleString('es-BO')}</dd></div>
        <div><dt className="text-xs text-slate-500">Última actualización</dt><dd className="text-slate-800">{tramite.ultima_actualizacion ? new Date(tramite.ultima_actualizacion).toLocaleString('es-BO') : '—'}</dd></div>
        {tramite.financiamiento && <div><dt className="text-xs text-slate-500">Financiamiento</dt><dd className="text-slate-800">{tramite.financiamiento.replaceAll('_', ' ')}</dd></div>}
      </dl>

      {Number(tramite.observaciones_pendientes) > 0 && (
        <p className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          Tiene {tramite.observaciones_pendientes} observación(es) pendiente(s). Ingrese al sistema o acuda a ventanilla para subsanarlas.
        </p>
      )}

      <AvanceSla tramite={tramite} />

      {tramite.flujo?.length > 0 && (
        <ol className="flex flex-wrap gap-x-4 gap-y-2 text-xs">
          {tramite.flujo.map((paso) => (
            <li key={paso.orden} className={`flex items-center gap-1 ${paso.completado ? 'text-emerald-700' : 'text-slate-400'}`}>
              {paso.completado ? <CheckCircle2 className="h-3.5 w-3.5" /> : <Circle className="h-3.5 w-3.5" />}{paso.paso}
            </li>
          ))}
        </ol>
      )}

      {tramite.descarga_disponible && (
        <div className="flex flex-wrap items-center gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm">
          <span className="font-medium text-emerald-800">Certificado N° {tramite.nro_certificado} disponible</span>
          <button type="button" onClick={descargar} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 font-medium text-white hover:bg-emerald-800">
            <Download className="h-4 w-4" />Descargar PDF/A
          </button>
          <Link to={`/validar-certificado?codigo=${tramite.codigo_verificacion}`} className="inline-flex items-center gap-1 text-emerald-800 hover:underline">
            <ShieldCheck className="h-4 w-4" />Validar autenticidad
          </Link>
        </div>
      )}
      <Alerta mensaje={error} />
    </article>
  );
}

/** CU6 / RF-16 - Portal público de seguimiento con Registro Universitario y C.I. */
export default function Seguimiento() {
  const [registro, setRegistro] = useState('');
  const [ci, setCi] = useState('');
  const [tramites, setTramites] = useState(null);
  const [buscando, setBuscando] = useState(false);
  const [error, setError] = useState('');

  const consultar = async (event) => {
    event.preventDefault();
    setError('');
    setBuscando(true);
    try {
      setTramites((await consultarSeguimiento(registro.trim(), ci.trim())).data);
    } catch (err) {
      setTramites(null);
      setError(err.response?.status === 429 ? 'Demasiadas consultas. Espere un minuto e intente nuevamente.' : mensajeError(err, 'No se pudo realizar la consulta.'));
    } finally {
      setBuscando(false);
    }
  };

  return (
    <LayoutPublico titulo="Seguimiento de trámites" subtitulo="Consulte el estado de sus trámites y descargue su certificado con su Registro Universitario y C.I.">
      <form onSubmit={consultar} className="grid gap-3 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <label className="text-sm">
          <span className="mb-1 block font-medium text-slate-700">Registro Universitario</span>
          <input required maxLength={20} value={registro} onChange={(e) => setRegistro(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2" />
        </label>
        <label className="text-sm">
          <span className="mb-1 block font-medium text-slate-700">Cédula de Identidad (C.I.)</span>
          <input required maxLength={20} value={ci} onChange={(e) => setCi(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2" />
        </label>
        <button type="submit" disabled={buscando} className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
          <Search className="h-4 w-4" />{buscando ? 'Consultando...' : 'Consultar'}
        </button>
      </form>
      <Alerta mensaje={error} />
      {tramites?.map((tramite) => <TarjetaTramite key={tramite.codigo} tramite={tramite} />)}
    </LayoutPublico>
  );
}
