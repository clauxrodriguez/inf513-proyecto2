import { useCallback, useEffect, useState } from 'react';
import { crearDelegacion, obtenerDelegaciones, revocarDelegacion } from '../api/delegacionesApi';
import Alerta from '../components/ui/Alerta';
import FormSuplencia from '../components/delegaciones/FormSuplencia';
import TablaDelegaciones from '../components/delegaciones/TablaDelegaciones';
import { mensajeErrorApi } from '../components/tramites/calculosTramite';

const FORM_DELEGACION = {
  titular_id: '', suplente_id: '', nro_resolucion: '', fecha_resolucion: '', motivo: '', detalle_motivo: '', fecha_inicio: '', fecha_fin: '',
};
const DATOS_VACIOS = { delegaciones: [], vicerrectores: [], suplentes: [], certificados: [] };

/** Validación del cliente de la suplencia (mismos criterios que el servidor). */
function validarSuplencia(form, estatutarioId) {
  const requeridos = ['titular_id', 'suplente_id', 'nro_resolucion', 'fecha_resolucion', 'motivo', 'fecha_inicio', 'fecha_fin'];
  if (requeridos.some((campo) => !String(form[campo]).trim())) return 'Complete todos los campos obligatorios de la suplencia.';
  if (form.fecha_fin < form.fecha_inicio) return 'La fecha de fin debe ser igual o posterior a la de inicio.';
  if (form.fecha_resolucion > form.fecha_inicio) return 'La resolución debe ser anterior o igual al inicio de la suplencia.';
  if (form.motivo === 'OTRO' && !form.detalle_motivo.trim()) return 'Describa el motivo cuando selecciona «Otro».';
  if (String(form.suplente_id) !== String(estatutarioId) && form.detalle_motivo.trim().length < 10) {
    return 'Para designar a un Decano distinto al que corresponde por prelación, justifique el impedimento en el detalle (al menos 10 caracteres).';
  }
  return '';
}

/** Delegación de autoridad (suplencia del Vicerrector), con la consulta de los certificados de firma. */
export default function Delegaciones() {
  const [datos, setDatos] = useState(DATOS_VACIOS);
  const [error, setError] = useState('');
  const [mensaje, setMensaje] = useState('');
  const [formDel, setFormDel] = useState(FORM_DELEGACION);
  const [enviando, setEnviando] = useState(false);

  const cargar = useCallback(async () => {
    try {
      const res = await obtenerDelegaciones();
      setDatos(res.data);
      setFormDel((previo) => ({ ...previo, titular_id: previo.titular_id || res.data.vicerrectores[0]?.id || '' }));
    } catch (err) {
      setError(mensajeErrorApi(err, 'No se pudo cargar el panel de delegaciones.'));
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const ejecutar = async (accion, alTerminar) => {
    setError('');
    setMensaje('');
    setEnviando(true);
    try {
      const res = await accion();
      setMensaje(res.data.message);
      alTerminar?.();
      await cargar();
    } catch (err) {
      setError(mensajeErrorApi(err, 'No se pudo completar la operación.'));
    } finally {
      setEnviando(false);
    }
  };

  const crearSuplencia = (event) => {
    event.preventDefault();
    const fallo = validarSuplencia(formDel, datos.suplente_estatutario_id);
    if (fallo) { setMensaje(''); setError(fallo); return; }
    ejecutar(() => crearDelegacion(formDel), () => setFormDel({ ...FORM_DELEGACION, titular_id: formDel.titular_id }));
  };

  const revocar = (id) => {
    const motivo = window.prompt('Motivo de la revocación (reasunción de funciones del titular):');
    if (!motivo) return;
    if (motivo.trim().length < 5) { setError('El motivo de la revocación debe tener al menos 5 caracteres.'); return; }
    ejecutar(() => revocarDelegacion(id, motivo.trim()));
  };

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-bold text-slate-800">Delegación de Autoridad</h1>
        <p className="text-sm text-slate-500">Suplencia del Vicerrector/a por Resolución Rectoral según la prelación de facultades</p>
      </header>
      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />
      <div className="max-w-3xl">
        <FormSuplencia datos={datos} form={formDel} setForm={setFormDel} onSubmit={crearSuplencia} enviando={enviando} />
      </div>
      <TablaDelegaciones datos={datos} onRevocar={revocar} />
    </div>
  );
}
