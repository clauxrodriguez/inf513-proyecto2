import { useCallback, useEffect, useState } from 'react';
import { ExternalLink, QrCode, RefreshCw, X } from 'lucide-react';
import { emitirCertificado, obtenerCertificadoPdf, obtenerCertificados, urlValidador } from '../api/certificadosApi';
import { mensajeError } from '../api/despachoApi';
import ModalAccionCertificado from '../components/certificados/ModalAccionCertificado';
import TablaEmitidos from '../components/certificados/TablaEmitidos';
import TablaListos from '../components/certificados/TablaListos';
import VisorPdf from '../components/despacho/VisorPdf';
import Alerta from '../components/ui/Alerta';
import { useAuth } from '../context/AuthContext';

/** CU6 - Emisión de certificados oficiales con QR (Recepción) y consulta/anulación (Recepción y autoridades). */
export default function Certificados() {
  const { tienePermiso } = useAuth();
  const esRecepcion = tienePermiso('TRAMITES_RECEPCION');
  const [datos, setDatos] = useState({ listos: [], emitidos: [] });
  const [pestana, setPestana] = useState(esRecepcion ? 'listos' : 'emitidos');
  const [cargando, setCargando] = useState(true);
  const [emitiendo, setEmitiendo] = useState(null);
  const [visto, setVisto] = useState(null);
  const [accion, setAccion] = useState(null);
  const [mensaje, setMensaje] = useState('');
  const [error, setError] = useState('');

  const cargar = useCallback(async () => {
    try {
      const res = await obtenerCertificados();
      setDatos(res.data);
    } catch (err) {
      setError(mensajeError(err, 'No se pudieron cargar los certificados.'));
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const emitir = async (tramite) => {
    const tipo = tramite.tipo === 'DESCUENTO_DIPLOMADO' ? 'la constancia de descuento' : 'el certificado de legalización';
    if (!window.confirm(`¿Emitir ${tipo} del trámite ${tramite.codigo}? El trámite quedará CONCLUIDO.`)) return;
    setError('');
    setMensaje('');
    setEmitiendo(tramite.id);
    try {
      const res = await emitirCertificado(tramite.id);
      setMensaje(res.data.message);
      await cargar();
      setPestana('emitidos');
      setVisto(res.data.certificado);
    } catch (err) {
      setError(mensajeError(err, 'No se pudo emitir el certificado.'));
    } finally {
      setEmitiendo(null);
    }
  };

  const alTerminarAccion = async (texto) => {
    setAccion(null);
    setVisto(null);
    setMensaje(texto);
    await cargar();
  };

  if (cargando) return <p className="text-sm text-slate-500">Cargando certificados...</p>;

  const pestanas = [
    ...(esRecepcion ? [['listos', `Listos para emisión (${datos.listos.length})`]] : []),
    ['emitidos', `Certificados emitidos (${datos.emitidos.length})`],
  ];

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-bold text-slate-800"><QrCode className="h-5 w-5 text-blue-900" aria-hidden="true" />Certificados Oficiales con QR</h1>
          <p className="text-sm text-slate-500">Certificado de legalización (CL-) o constancia de descuento (CD-) en PDF/A firmado, con QR de verificación pública</p>
        </div>
        <button type="button" onClick={cargar} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-white">
          <RefreshCw className="h-4 w-4" />Actualizar
        </button>
      </header>

      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />

      <div className="flex gap-1 border-b border-slate-200">
        {pestanas.map(([clave, etiqueta]) => (
          <button key={clave} type="button" onClick={() => setPestana(clave)}
            className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium ${pestana === clave ? 'border-blue-900 text-blue-900' : 'border-transparent text-slate-500 hover:text-slate-700'}`}>
            {etiqueta}
          </button>
        ))}
      </div>

      {pestana === 'listos'
        ? <TablaListos tramites={datos.listos} emitiendo={emitiendo} onEmitir={emitir} />
        : <TablaEmitidos certificados={datos.emitidos} activo={visto?.id}
            onVer={setVisto} onAnular={(c) => setAccion({ certificado: c })} />}

      {visto && (
        <section className="space-y-3">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="text-lg font-semibold text-slate-800">{visto.numero} · {visto.tramite_codigo}</h2>
            <div className="flex items-center gap-3 text-sm">
              <a href={urlValidador(visto.codigo_verificacion, visto.hash_sha256)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-blue-800 hover:underline">
                <ExternalLink className="h-4 w-4" />Abrir validador público
              </a>
              <button type="button" onClick={() => setVisto(null)} className="inline-flex items-center gap-1 text-slate-600 hover:text-slate-900"><X className="h-4 w-4" />Cerrar</button>
            </div>
          </div>
          <p className="break-all font-mono text-xs text-slate-500">Código de verificación {visto.codigo_verificacion} · SHA-256 del expediente {visto.hash_sha256}</p>
          <VisorPdf clave={`certificado-${visto.id}`} cargar={() => obtenerCertificadoPdf(visto.id)} />
        </section>
      )}

      {accion && (
        <ModalAccionCertificado certificado={accion.certificado} onCerrar={() => setAccion(null)} onHecho={alTerminarAccion} />
      )}
    </div>
  );
}
