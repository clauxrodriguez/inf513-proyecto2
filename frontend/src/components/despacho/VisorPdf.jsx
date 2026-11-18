import { useEffect, useState } from 'react';
import { FileText } from 'lucide-react';
import { mensajeErrorBlob, obtenerLegajoPdf } from '../../api/despachoApi';
import Alerta from '../ui/Alerta';

/**
 * Visor integrado de PDF (RF-11): el documento se obtiene autenticado y se muestra por páginas
 * con el visor del navegador, sin descargarlo.
 * Por defecto muestra el legajo del trámite; con `cargar` + `clave` muestra cualquier otro PDF (p. ej. un certificado).
 */
export default function VisorPdf({ tramiteId, cargar, clave, version = 0, onCargado, alto = 'h-[72vh]' }) {
  const [estado, setEstado] = useState({ url: '', cargando: true, error: '' });

  useEffect(() => {
    let vigente = true;
    let url = '';
    setEstado({ url: '', cargando: true, error: '' });
    (cargar ? cargar() : obtenerLegajoPdf(tramiteId))
      .then((res) => {
        if (!vigente) return;
        url = URL.createObjectURL(new Blob([res.data], { type: 'application/pdf' }));
        setEstado({ url, cargando: false, error: '' });
        onCargado?.();
      })
      .catch(async (err) => {
        const error = await mensajeErrorBlob(err, 'No se pudo cargar el legajo PDF.');
        if (vigente) setEstado({ url: '', cargando: false, error });
      });
    return () => {
      vigente = false;
      if (url) URL.revokeObjectURL(url);
    };
    // onCargado y cargar se omiten a propósito: solo se recarga al cambiar de documento o de versión
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tramiteId, clave, version]);

  if (estado.error) return <Alerta mensaje={estado.error} />;
  if (estado.cargando) {
    return (
      <div className={`flex ${alto} flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-white text-sm text-slate-500`}>
        <FileText className="h-8 w-8 animate-pulse text-slate-400" />
        {cargar ? 'Cargando documento...' : 'Compilando y cargando el legajo foliado...'}
      </div>
    );
  }
  return <iframe title="Legajo PDF" src={`${estado.url}#view=FitH`} className={`w-full ${alto} rounded-xl border border-slate-200 bg-slate-100`} />;
}
