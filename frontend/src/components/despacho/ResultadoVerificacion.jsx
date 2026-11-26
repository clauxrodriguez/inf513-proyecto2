import { useEffect, useState } from 'react';
import { CheckCircle2, ShieldCheck, ShieldX, XCircle } from 'lucide-react';
import { mensajeError, verificarFirmas } from '../../api/despachoApi';
import Alerta from '../ui/Alerta';

function Chequeo({ ok, texto }) {
  return (
    <span className={`inline-flex items-center gap-1 ${ok ? 'text-emerald-700' : 'text-red-600'}`}>
      {ok ? <CheckCircle2 className="h-3.5 w-3.5" /> : <XCircle className="h-3.5 w-3.5" />}{texto}
    </span>
  );
}

/** Verificación criptográfica de las firmas PAdES incrustadas en el PDF del trámite. */
export default function ResultadoVerificacion({ tramiteId, version = 0 }) {
  const [resultado, setResultado] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let vigente = true;
    setResultado(null);
    setError('');
    verificarFirmas(tramiteId)
      .then((res) => { if (vigente) setResultado(res.data); })
      .catch((err) => { if (vigente) setError(mensajeError(err, 'No se pudo verificar la firma digital.')); });
    return () => { vigente = false; };
  }, [tramiteId, version]);

  if (error) return <Alerta mensaje={error} />;
  if (!resultado) return <p className="text-sm text-slate-500">Verificando firmas digitales...</p>;

  return <TarjetaVerificacion resultado={resultado} />;
}

/** Presentación del resultado de verificar las firmas de un PDF (también la usa el validador público). */
export function TarjetaVerificacion({ resultado }) {
  const sinFirmas = !resultado.totalFirmas;
  const estilo = resultado.valido
    ? 'border-emerald-200 bg-emerald-50/60'
    : sinFirmas
      ? 'border-slate-200 bg-slate-50'
      : 'border-red-200 bg-red-50';

  return (
    <section className={`rounded-xl border p-4 text-sm ${estilo}`}>
      <h3 className="flex items-center gap-2 font-semibold text-slate-800">
        {resultado.valido ? (
          <ShieldCheck className="h-5 w-5 text-emerald-700" />
        ) : (
          <ShieldX className={`h-5 w-5 ${sinFirmas ? 'text-slate-500' : 'text-red-600'}`} />
        )}
        {resultado.valido
          ? 'Firmas digitales válidas'
          : sinFirmas
            ? 'Legajo listo para firma de despacho'
            : 'Firmas con observaciones'}
      </h3>
      <p className="mt-1 break-all font-mono text-[11px] text-slate-500">SHA-256 del PDF: {resultado.hashSha256}</p>
      {sinFirmas && (
        <p className="mt-2 text-xs text-slate-600">
          Las firmas de origen fueron verificadas y estampadas al compilar el legajo. Este expediente aún no tiene firmas de despacho ni de Rectorado.
        </p>
      )}
      <ol className="mt-3 space-y-3">
        {resultado.firmas.map((firma) => (
          <li key={firma.orden} className="rounded-lg border border-slate-200 bg-white p-3">
            <p className="font-medium text-slate-800">{firma.orden}. {firma.firmante || firma.nombre}</p>
            <p className="text-xs text-slate-600">{firma.razon}</p>
            <p className="text-xs text-slate-500">{firma.ubicacion} · {firma.fecha ? new Date(firma.fecha).toLocaleString('es-BO') : '—'}</p>
            <p className="text-xs text-slate-500">Emisor: {firma.emisor || '—'} · Serie <span className="font-mono">{firma.numeroSerie || '—'}</span></p>
            <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs">
              <Chequeo ok={firma.integra} texto="Íntegra" />
              <Chequeo ok={firma.confiable} texto="Cadena de confianza" />
              <Chequeo ok={firma.certificadoVigenteAlFirmar} texto="Certificado vigente" />
              <Chequeo ok={firma.signingCertificateV2} texto="signing-certificate-v2" />
              <span className="text-slate-500">{firma.subFiltro}</span>
            </div>
            {!firma.integra && <p className="mt-1 text-xs text-red-600">{firma.detalle}</p>}
          </li>
        ))}
      </ol>
    </section>
  );
}
