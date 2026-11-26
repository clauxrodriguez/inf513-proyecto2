import { useCallback, useEffect, useState } from 'react';
import { BadgeCheck, FlaskConical, KeyRound, MapPin, PenTool, RefreshCw, ShieldAlert, Usb } from 'lucide-react';
import { confirmarLoteFirmado, prepararFirma, registrarCertificadoToken } from '../../api/firmasApi';
import { firmarLote, leerCertificados, listarTokens, verificarEstado } from '../../services/jacobitusService';
import { mensajeErrorApi } from '../tramites/calculosTramite';
import Alerta from '../ui/Alerta';
import Modal from '../ui/Modal';

const JACOBITUS_URL = import.meta.env.VITE_JACOBITUS_URL || 'https://localhost:9000';

/** Mismo número de serie aunque uno venga en decimal y otro en hexadecimal, con o sin separadores. */
const formasSerie = (serie) => {
  const limpia = String(serie || '').replace(/[^0-9a-f]/gi, '').toLowerCase().replace(/^0+/, '');
  const formas = new Set([limpia]);
  if (/^\d+$/.test(String(serie || '').trim())) formas.add(BigInt(String(serie).trim()).toString(16));
  return formas;
};
const mismaSerie = (a, b) => [...formasSerie(a)].some((forma) => forma && formasSerie(b).has(forma));
const mensaje = (err, respaldo) => (err?.response ? mensajeErrorApi(err, respaldo) : err?.message || respaldo);

/**
 * Firma digital con Jacobitus (ORIGEN, DESPACHO o RECTORADO). El PIN se usa solo contra Jacobitus local;
 * el backend recibe los PDF co-firmados, verifica cada firma y la registra.
 * "Modo simulación" usa la firma en el servidor (microservicio) para demostraciones sin Jacobitus.
 */
export default function ModalFirmaJacobitus({ etapa, documentos, habilitacion, permitirRemota = true, onCerrar, onFirmado, firmarSimulado }) {
  const [paso, setPaso] = useState('verificando');
  const [tokens, setTokens] = useState([]);
  const [slot, setSlot] = useState('');
  const [pin, setPin] = useState('');
  const [certificados, setCertificados] = useState([]);
  const [alias, setAlias] = useState('');
  const [modalidad, setModalidad] = useState('PRESENCIAL');
  const [ubicacion, setUbicacion] = useState('');
  const [progreso, setProgreso] = useState('');
  const [simulacion, setSimulacion] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState('');

  const serieRegistrada = habilitacion?.certificado?.numero_serie;
  const certificado = certificados.find((c) => c.alias === alias);
  const coincide = certificado && serieRegistrada && mismaSerie(certificado.numeroSerie, serieRegistrada);

  const conectar = useCallback(async () => {
    setError('');
    setPaso('verificando');
    if (!(await verificarEstado())) { setPaso('sin_servicio'); return; }
    try {
      const lista = await listarTokens();
      setTokens(lista);
      setSlot(lista[0] ? String(lista[0].slot) : '');
      setPaso(lista.length ? 'pin' : 'sin_token');
    } catch (err) {
      const msg = mensaje(err, 'No se pudieron listar los tokens.');
      if (/ningun token|ningún token|no.*token/i.test(msg)) {
        setTokens([]);
        setPaso('sin_token');
      } else {
        setError(msg);
        setPaso('sin_servicio');
      }
    }
  }, []);

  useEffect(() => { conectar(); }, [conectar]);

  const ejecutar = async (accion) => {
    setError('');
    setOcupado(true);
    try {
      await accion();
    } catch (err) {
      setError(mensaje(err, 'No se pudo completar la firma digital.'));
    } finally {
      setOcupado(false);
      setProgreso('');
    }
  };

  const validarDatos = () => {
    if (pin.length < 4) { setError('Ingrese el PIN de su token o softoken.'); return false; }
    if (modalidad === 'REMOTA' && !ubicacion.trim()) { setError('Indique su ubicación actual para la firma remota.'); return false; }
    return true;
  };

  const leer = (event) => {
    event.preventDefault();
    if (!validarDatos()) return;
    ejecutar(async () => {
      const lista = await leerCertificados(slot, pin);
      if (!lista.length) throw new Error('El token no contiene certificados de firma.');
      setCertificados(lista);
      setAlias((lista.find((c) => serieRegistrada && mismaSerie(c.numeroSerie, serieRegistrada)) || lista[0]).alias);
      setPaso('certificado');
    });
  };

  const registrar = () => ejecutar(async () => {
    if (!certificado.desde || !certificado.hasta) throw new Error('Jacobitus no informó la vigencia del certificado.');
    const res = await registrarCertificadoToken({
      numero_serie: certificado.numeroSerie, emisor: certificado.emisor, fecha_emision: certificado.desde, fecha_expiracion: certificado.hasta,
    });
    onFirmado(`${res.data.message} Vuelva a abrir la firma para continuar.`);
  });

  const firmar = () => ejecutar(async () => {
    setProgreso('Preparando documentos...');
    const preparados = (await prepararFirma(etapa, documentos.map((d) => d.id))).data;
    const firmados = await firmarLote(slot, pin, alias, preparados.documentos,
      (hechos, total) => setProgreso(`Firmando con Jacobitus: ${hechos} de ${total}...`));
    setProgreso('Verificando y registrando las firmas...');
    const res = await confirmarLoteFirmado({ etapa, documentos: firmados, modalidad, ubicacion: modalidad === 'REMOTA' ? ubicacion.trim() : null });
    setPin('');
    onFirmado(res.data.message);
  });

  const firmarEnSimulacion = (event) => {
    event.preventDefault();
    if (pin.length < 6) { setError('El PIN del certificado de prueba tiene al menos 6 caracteres.'); return; }
    if (modalidad === 'REMOTA' && !ubicacion.trim()) { setError('Indique su ubicación actual para la firma remota.'); return; }
    ejecutar(async () => {
      const res = await firmarSimulado({ pin, modalidad, ubicacion: modalidad === 'REMOTA' ? ubicacion.trim() : null });
      setPin('');
      onFirmado(res.data.message);
    });
  };

  const camposComunes = (
    <>
      {permitirRemota && (
        <fieldset>
          <legend className="mb-1 font-medium text-slate-700">Modalidad</legend>
          <div className="grid grid-cols-2 gap-2">
            {[['PRESENCIAL', 'Presencial (campus)'], ['REMOTA', 'Remota']].map(([valor, etiqueta]) => (
              <label key={valor} className={`cursor-pointer rounded-lg border px-3 py-2 text-xs ${modalidad === valor ? 'border-blue-800 bg-blue-50 font-medium text-blue-900' : 'border-slate-300 text-slate-600'}`}>
                <input type="radio" name="modalidad" value={valor} checked={modalidad === valor} onChange={() => setModalidad(valor)} className="sr-only" />{etiqueta}
              </label>
            ))}
          </div>
        </fieldset>
      )}
      {modalidad === 'REMOTA' && (
        <label className="block">
          <span className="mb-1 flex items-center gap-1 font-medium text-slate-700"><MapPin className="h-3.5 w-3.5" aria-hidden="true" />Ubicación actual *</span>
          <input maxLength={120} value={ubicacion} onChange={(e) => setUbicacion(e.target.value)} placeholder="Ej.: La Paz, Bolivia" className="w-full rounded-lg border border-slate-300 px-3 py-2" />
        </label>
      )}
    </>
  );

  return (
    <Modal titulo={documentos.length > 1 ? `Firmar lote (${documentos.length} documentos)` : 'Firmar digitalmente'}>
      <div className="space-y-4 text-sm">
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
          <p className="font-medium text-slate-800">{habilitacion?.razon || habilitacion?.leyenda}</p>
          <p className="mt-1 text-xs text-slate-500">
            {serieRegistrada ? <>Certificado registrado N° <span className="font-mono">{serieRegistrada}</span></> : 'Aún no registró su certificado de firma.'}
          </p>
          <ul className="mt-2 max-h-24 space-y-0.5 overflow-y-auto text-xs text-slate-600">
            {documentos.map((d) => <li key={d.id}>• {d.etiqueta}</li>)}
          </ul>
        </div>

        {simulacion ? (
          <form onSubmit={firmarEnSimulacion} noValidate className="space-y-4">
            <p className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
              <FlaskConical className="h-4 w-4 shrink-0" aria-hidden="true" />
              Modo simulación: firma en el servidor con el certificado de prueba emitido por el sistema. El PIN se envía al backend; úselo solo en demostraciones.
            </p>
            {camposComunes}
            <label className="block">
              <span className="mb-1 flex items-center gap-1 font-medium text-slate-700"><KeyRound className="h-3.5 w-3.5" aria-hidden="true" />PIN del certificado de prueba *</span>
              <input type="password" autoComplete="off" value={pin} onChange={(e) => setPin(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2 tracking-widest" />
            </label>
            <div className="flex justify-between gap-2">
              <button type="button" onClick={() => { setSimulacion(false); setPin(''); setError(''); }} className="text-xs text-blue-800 hover:underline">Volver a Jacobitus</button>
              <button type="submit" disabled={ocupado} className="inline-flex items-center gap-2 rounded-lg bg-amber-700 px-4 py-2 font-medium text-white hover:bg-amber-800 disabled:opacity-50">
                <PenTool className="h-4 w-4" aria-hidden="true" />{ocupado ? 'Firmando...' : 'Firmar en simulación'}
              </button>
            </div>
          </form>
        ) : (
          <>
            {paso === 'verificando' && <p className="text-slate-500">Buscando Jacobitus en {JACOBITUS_URL}...</p>}

            {(paso === 'sin_servicio' || paso === 'sin_token') && (
              <div role="alert" className="space-y-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                <p className="flex items-center gap-2 font-semibold"><ShieldAlert className="h-4 w-4" aria-hidden="true" />
                  {paso === 'sin_token' ? 'Jacobitus no detecta ningún token o softoken conectado.' : 'Jacobitus no está disponible.'}
                </p>
                {paso === 'sin_servicio' && <p>Inicie Jacobitus y abra <a href={JACOBITUS_URL} target="_blank" rel="noreferrer" className="font-medium underline">{JACOBITUS_URL}</a> una vez para aceptar su certificado de seguridad.</p>}
                <button type="button" onClick={conectar} className="inline-flex items-center gap-1 font-medium text-blue-800 hover:underline">
                  <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />Reintentar
                </button>
              </div>
            )}

            {paso === 'pin' && (
              <form onSubmit={leer} noValidate className="space-y-4">
                <label className="block">
                  <span className="mb-1 flex items-center gap-1 font-medium text-slate-700"><Usb className="h-3.5 w-3.5" aria-hidden="true" />Token / softoken *</span>
                  <select value={slot} onChange={(e) => setSlot(e.target.value)} className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                    {tokens.map((t) => <option key={t.slot} value={t.slot}>{t.nombre}</option>)}
                  </select>
                </label>
                {camposComunes}
                <label className="block">
                  <span className="mb-1 flex items-center gap-1 font-medium text-slate-700"><KeyRound className="h-3.5 w-3.5" aria-hidden="true" />PIN del token *</span>
                  <input type="password" autoComplete="off" autoFocus value={pin} onChange={(e) => setPin(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2 tracking-widest" />
                  <span className="mt-1 block text-xs text-slate-500">El PIN se usa solo en su PC con Jacobitus; no se envía al servidor.</span>
                </label>
                <div className="flex justify-end">
                  <button type="submit" disabled={ocupado} className="rounded-lg bg-blue-900 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-50">
                    {ocupado ? 'Leyendo token...' : 'Continuar'}
                  </button>
                </div>
              </form>
            )}

            {paso === 'certificado' && certificado && (
              <div className="space-y-4">
                {certificados.length > 1 && (
                  <label className="block">
                    <span className="mb-1 block font-medium text-slate-700">Certificado</span>
                    <select value={alias} onChange={(e) => setAlias(e.target.value)} className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
                      {certificados.map((c) => <option key={c.alias} value={c.alias}>{c.titular} · {c.numeroSerie}</option>)}
                    </select>
                  </label>
                )}
                <div className={`rounded-lg border p-3 text-xs ${coincide ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800'}`}>
                  <p className="flex items-center gap-1.5 font-semibold">
                    {coincide ? <BadgeCheck className="h-4 w-4" aria-hidden="true" /> : <ShieldAlert className="h-4 w-4" aria-hidden="true" />}
                    {coincide ? 'Certificado verificado: es su certificado registrado.' : 'Este certificado no es su certificado de firma registrado.'}
                  </p>
                  <p className="mt-1">{certificado.titular} · Serie <span className="font-mono">{certificado.numeroSerie}</span></p>
                  <p>{certificado.emisor} · vigente {certificado.desde} a {certificado.hasta}</p>
                </div>
                {progreso && <p role="status" className="text-xs text-slate-600">{progreso}</p>}
                <div className="flex flex-wrap justify-end gap-2">
                  {!coincide && (
                    <button type="button" onClick={registrar} disabled={ocupado} className="rounded-lg border border-blue-800 px-4 py-2 font-medium text-blue-900 hover:bg-blue-50 disabled:opacity-50">
                      Registrar este certificado como el mío
                    </button>
                  )}
                  <button type="button" onClick={firmar} disabled={ocupado || !coincide}
                    className="inline-flex items-center gap-2 rounded-lg bg-blue-900 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-50">
                    <PenTool className="h-4 w-4" aria-hidden="true" />{ocupado ? 'Firmando...' : 'Firmar con Jacobitus'}
                  </button>
                </div>
              </div>
            )}
          </>
        )}

        <Alerta mensaje={error} />
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 pt-3">
          {!simulacion && firmarSimulado
            ? <button type="button" onClick={() => { setSimulacion(true); setPin(''); setError(''); }} className="inline-flex items-center gap-1 text-xs text-slate-600 hover:underline">
              <FlaskConical className="h-3.5 w-3.5" aria-hidden="true" />Modo simulación (sin Jacobitus)
            </button>
            : <span />}
          <button type="button" onClick={onCerrar} disabled={ocupado} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-50">Cancelar</button>
        </div>
      </div>
    </Modal>
  );
}
