import { useState } from 'react';
import { BadgeCheck, Download, KeyRound, ShieldAlert } from 'lucide-react';
import { descargarCertificadoRaiz, mensajeErrorBlob } from '../../api/despachoApi';
import { InsigniaCalidad } from './Insignias';

export default function PanelHabilitacion({ habilitacion }) {
  const [error, setError] = useState('');
  if (!habilitacion) return null;
  const { calidad, delegacion, certificado, puede_firmar: puedeFirmar, motivo, razon } = habilitacion;

  const descargarRaiz = async () => {
    setError('');
    try {
      const res = await descargarCertificadoRaiz();
      const url = URL.createObjectURL(res.data);
      const enlace = document.createElement('a');
      enlace.href = url;
      enlace.download = 'ac-raiz-prueba-uagrm.crt';
      enlace.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      setError(await mensajeErrorBlob(err, 'No se pudo descargar el certificado raíz.'));
    }
  };

  return (
    <section className={`rounded-xl border p-4 ${puedeFirmar ? 'border-emerald-200 bg-emerald-50/60' : 'border-amber-200 bg-amber-50'}`}>
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          {puedeFirmar
            ? <BadgeCheck className="mt-0.5 h-5 w-5 shrink-0 text-emerald-700" />
            : <ShieldAlert className="mt-0.5 h-5 w-5 shrink-0 text-amber-700" />}
          <div className="space-y-1 text-sm">
            <p className="flex flex-wrap items-center gap-2 font-semibold text-slate-800">
              Habilitación para firmar <InsigniaCalidad calidad={calidad} />
            </p>
            {razon && <p className="text-slate-700">Firmará como: <span className="font-medium">{razon}</span></p>}
            {delegacion && (
              <p className="text-xs text-slate-600">
                Suplencia de {delegacion.titular} · vigente del {delegacion.fecha_inicio} al {delegacion.fecha_fin}
              </p>
            )}
            {!puedeFirmar && <p className="font-medium text-amber-800">{motivo}</p>}
          </div>
        </div>
        <div className="space-y-1 text-right text-xs text-slate-600">
          {certificado ? (
            <>
              <p className="flex items-center justify-end gap-1 font-medium text-slate-800"><KeyRound className="h-3.5 w-3.5" />Certificado N° <span className="font-mono">{certificado.numero_serie}</span></p>
              <p>{certificado.emisor}</p>
              <p>Vigente hasta {certificado.fecha_expiracion}</p>
            </>
          ) : <p className="font-medium text-amber-800">Sin certificado digital activo</p>}
          <button type="button" onClick={descargarRaiz} className="mt-1 inline-flex items-center gap-1 text-blue-800 hover:underline">
            <Download className="h-3.5 w-3.5" />Certificado raíz de la AC (para Adobe Reader)
          </button>
          {error && <p className="text-red-600">{error}</p>}
        </div>
      </div>
    </section>
  );
}
