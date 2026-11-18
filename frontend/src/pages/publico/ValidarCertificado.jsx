import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AlertTriangle, BadgeCheck, Ban, Download, FileSearch, HelpCircle, Search } from 'lucide-react';
import { abrirArchivo } from '../../api/archivos';
import { mensajeError } from '../../api/despachoApi';
import { descargarCertificadoPublico, verificarArchivoPdf, verificarCertificado } from '../../api/publicoApi';
import { TarjetaVerificacion } from '../../components/despacho/ResultadoVerificacion';
import LayoutPublico from '../../components/publico/LayoutPublico';
import Alerta from '../../components/ui/Alerta';

const DICTAMEN = {
  VALIDO: { icono: BadgeCheck, estilo: 'border-emerald-300 bg-emerald-50 text-emerald-800', titulo: 'CERTIFICADO VÁLIDO', texto: 'El documento es auténtico y se encuentra vigente.' },
  ANULADO: { icono: Ban, estilo: 'border-red-300 bg-red-50 text-red-800', titulo: 'CERTIFICADO ANULADO', texto: 'El documento fue anulado de forma definitiva y no tiene efecto.' },
  HASH_ALTERADO: { icono: AlertTriangle, estilo: 'border-amber-300 bg-amber-50 text-amber-800', titulo: 'HASH ALTERADO', texto: 'El código existe, pero la huella del expediente no coincide: el documento presentado pudo ser modificado.' },
  NO_ENCONTRADO: { icono: HelpCircle, estilo: 'border-slate-300 bg-white text-slate-700', titulo: 'NO ENCONTRADO', texto: 'No existe un certificado emitido con ese código de verificación.' },
};

function Dictamen({ resultado }) {
  const [error, setError] = useState('');
  const config = DICTAMEN[resultado.resultado] || DICTAMEN.NO_ENCONTRADO;
  const Icono = config.icono;
  const descargar = async () => {
    setError((await abrirArchivo(() => descargarCertificadoPublico(resultado.codigo))) || '');
  };

  return (
    <section className={`space-y-3 rounded-xl border-2 p-5 ${config.estilo}`}>
      <h2 className="flex items-center gap-2 text-lg font-bold"><Icono className="h-6 w-6" />{config.titulo}</h2>
      <p className="text-sm">{config.texto}{resultado.resultado === 'VALIDO' && !resultado.hash_verificado ? ' (Para comprobar también la huella del expediente, escanee el QR del documento.)' : ''}</p>
      {resultado.numero && (
        <dl className="grid gap-x-6 gap-y-2 rounded-lg bg-white/70 p-4 text-sm text-slate-800 sm:grid-cols-2">
          <div><dt className="text-xs text-slate-500">N° de certificado</dt><dd className="font-semibold">{resultado.numero}</dd></div>
          <div><dt className="text-xs text-slate-500">Tipo</dt><dd>{resultado.tipo === 'CONSTANCIA_DESCUENTO' ? 'Constancia de descuento' : 'Certificado de legalización'}</dd></div>
          <div><dt className="text-xs text-slate-500">Trámite</dt><dd>{resultado.tramite}</dd></div>
          <div><dt className="text-xs text-slate-500">Titular</dt><dd>{resultado.titular}</dd></div>
          <div><dt className="text-xs text-slate-500">Fecha de emisión</dt><dd>{new Date(resultado.fecha_emision).toLocaleString('es-BO')}</dd></div>
          {resultado.fecha_anulacion && <div><dt className="text-xs text-slate-500">Anulado el</dt><dd>{new Date(resultado.fecha_anulacion).toLocaleString('es-BO')}</dd></div>}
          <div className="sm:col-span-2"><dt className="text-xs text-slate-500">Autoridad firmante</dt><dd>{resultado.leyenda_firma}</dd></div>
          {resultado.motivo_anulacion && <div className="sm:col-span-2"><dt className="text-xs text-slate-500">Motivo de anulación</dt><dd>{resultado.motivo_anulacion}</dd></div>}
        </dl>
      )}
      {resultado.resultado === 'VALIDO' && (
        <button type="button" onClick={descargar} className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-800">
          <Download className="h-4 w-4" />Ver documento oficial
        </button>
      )}
      <Alerta mensaje={error} />
    </section>
  );
}

/** CU6 / RF-15 - Validador público de autenticidad (destino del QR del certificado). */
export default function ValidarCertificado() {
  const [parametros] = useSearchParams();
  const [codigo, setCodigo] = useState(parametros.get('codigo') || '');
  const [resultado, setResultado] = useState(null);
  const [verificacionArchivo, setVerificacionArchivo] = useState(null);
  const [cargando, setCargando] = useState(false);
  const [error, setError] = useState('');

  const consultar = async (valorCodigo, hash) => {
    setError('');
    setCargando(true);
    try {
      setResultado((await verificarCertificado(valorCodigo.trim(), hash)).data);
    } catch (err) {
      setResultado(null);
      setError(err.response?.status === 429 ? 'Demasiadas consultas. Espere un minuto.' : mensajeError(err, 'No se pudo verificar el certificado.'));
    } finally {
      setCargando(false);
    }
  };

  useEffect(() => {
    const codigoQr = parametros.get('codigo');
    if (codigoQr) consultar(codigoQr, parametros.get('hash'));
    // Solo al llegar desde el QR
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const verificarArchivo = async (event) => {
    const archivo = event.target.files?.[0];
    if (!archivo) return;
    setError('');
    setVerificacionArchivo(null);
    try {
      setVerificacionArchivo((await verificarArchivoPdf(archivo)).data);
    } catch (err) {
      setError(mensajeError(err, 'No se pudo verificar el archivo.'));
    }
    event.target.value = '';
  };

  return (
    <LayoutPublico titulo="Validar certificado" subtitulo="Compruebe la autenticidad y vigencia de un certificado o constancia emitida por el Vicerrectorado UAGRM">
      <form onSubmit={(e) => { e.preventDefault(); consultar(codigo, null); }} className="grid gap-3 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-[1fr_auto] sm:items-end">
        <label className="text-sm">
          <span className="mb-1 block font-medium text-slate-700">Código de verificación (impreso junto al QR)</span>
          <input required value={codigo} onChange={(e) => setCodigo(e.target.value)} placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" className="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono" />
        </label>
        <button type="submit" disabled={cargando} className="inline-flex items-center justify-center gap-2 rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
          <Search className="h-4 w-4" />{cargando ? 'Verificando...' : 'Verificar'}
        </button>
      </form>
      <Alerta mensaje={error} />
      {resultado && <Dictamen resultado={resultado} />}

      <section className="space-y-3 rounded-xl border border-slate-200 bg-white p-5 text-sm">
        <h2 className="flex items-center gap-2 font-semibold text-slate-800"><FileSearch className="h-4 w-4" />Verificar la firma digital de un archivo PDF</h2>
        <p className="text-slate-500">Si recibió el certificado en formato digital, súbalo para comprobar que su firma incrustada está íntegra (no fue modificado).</p>
        <input type="file" accept="application/pdf" onChange={verificarArchivo} className="block text-sm" />
        {verificacionArchivo && <TarjetaVerificacion resultado={verificacionArchivo} />}
      </section>
    </LayoutPublico>
  );
}
