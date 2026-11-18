import { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { crearCertificadoDigital, crearDelegacion as enviarDelegacion, obtenerDelegaciones, revocarDelegacion as enviarRevocacion } from '../api/delegacionesApi';
import Alerta from '../components/ui/Alerta';
import FormCertificado from '../components/delegaciones/FormCertificado';
import FormSuplencia from '../components/delegaciones/FormSuplencia';
import TablaDelegaciones from '../components/delegaciones/TablaDelegaciones';
import TarjetasIndicadores from '../components/delegaciones/TarjetasIndicadores';

const FORM_DELEGACION = {
  titular_id: '', suplente_id: '', tipo_resolucion: 'RECTORAL', nro_resolucion: '',
  fecha_resolucion: '', motivo: '', detalle_motivo: '', justificacion_prelacion: '',
  fecha_inicio: '', fecha_fin: '',
};
const FORM_CERTIFICADO = { usuario_id: '', dias_vigencia: '365', pin: '', pin_confirmation: '' };
const mensajeError = (err, fallback) => err.response?.data?.errors
  ? Object.values(err.response.data.errors).flat().join(' ')
  : err.response?.data?.message || fallback;

export default function Delegaciones() {
  const { user } = useAuth();
  const esRectorado = user?.rol === 'RECTORADO';
  const [datos, setDatos] = useState({ delegaciones: [], vicerrectores: [], suplentes: [], certificados: [], indicadores: {} });
  const [error, setError] = useState('');
  const [mensaje, setMensaje] = useState('');
  const [archivoPdf, setArchivoPdf] = useState(null);
  const [formDel, setFormDel] = useState(FORM_DELEGACION);
  const [formCert, setFormCert] = useState(FORM_CERTIFICADO);
  const [emitiendo, setEmitiendo] = useState(false);
  const cargar = async () => {
    const res = await obtenerDelegaciones();
    setDatos(res.data);
    if (res.data.vicerrectores.length && !formDel.titular_id) setFormDel((prev) => ({ ...prev, titular_id: res.data.vicerrectores[0].id }));
  };
  useEffect(() => { cargar(); }, []);
  const crearSuplencia = async (event) => {
    event.preventDefault(); setError(''); setMensaje('');
    if (!archivoPdf) { setError('La suplencia carece de sustento legal: debe adjuntar el archivo PDF de la resolución.'); return; }
    const formData = new FormData();
    Object.entries(formDel).forEach(([key, value]) => formData.append(key, value));
    formData.append('archivo_resolucion', archivoPdf);
    try {
      const res = await enviarDelegacion(formData); setMensaje(res.data.message);
      setFormDel({ ...FORM_DELEGACION, titular_id: formDel.titular_id });
      setArchivoPdf(null); await cargar();
    } catch (err) { setError(mensajeError(err, 'Error al registrar delegación.')); }
  };
  const revocar = async (id) => {
    const motivo_revocacion = window.prompt('Ingrese el motivo oficial de revocación / reasunción de funciones:');
    if (!motivo_revocacion) return;
    setError(''); setMensaje('');
    try { const res = await enviarRevocacion(id, motivo_revocacion); setMensaje(res.data.message); await cargar(); }
    catch (err) { setError(mensajeError(err, 'Error al revocar delegación.')); }
  };
  const crearCertificado = async (event) => {
    event.preventDefault(); setError(''); setMensaje('');
    if (formCert.pin !== formCert.pin_confirmation) { setError('La confirmación del PIN no coincide.'); return; }
    setEmitiendo(true);
    try {
      const res = await crearCertificadoDigital(formCert); setMensaje(res.data.message);
      setFormCert(FORM_CERTIFICADO); await cargar();
    } catch (err) { setError(mensajeError(err, 'Error al emitir el certificado.')); }
    finally { setEmitiendo(false); }
  };

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-bold text-slate-800">Delegación de Autoridad e Indicadores de Gestión (CU2)</h1>
        <p className="text-sm text-slate-500">Suplencias por Resolución Rectoral / ICU, certificados digitales ADSIB y métricas de despacho en tiempo real</p>
      </header>
      <TarjetasIndicadores indicadores={datos.indicadores} />
      {esRectorado && <div className="grid lg:grid-cols-2 gap-6">
        <FormSuplencia datos={datos} form={formDel} setForm={setFormDel} setArchivoPdf={setArchivoPdf} onSubmit={crearSuplencia} />
        <FormCertificado datos={datos} form={formCert} setForm={setFormCert} onSubmit={crearCertificado} enviando={emitiendo} />
      </div>}
      <TablaDelegaciones datos={datos} esRectorado={esRectorado} onRevocar={revocar} />
      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />
    </div>
  );
}
