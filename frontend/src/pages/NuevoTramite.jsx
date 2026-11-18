import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { crearTramite, obtenerCatalogos } from '../api/tramitesApi';
import { useAuth } from '../context/AuthContext';
import Alerta from '../components/ui/Alerta';
import DatosTramite from '../components/tramites/DatosTramite';
import FormDescuento from '../components/tramites/FormDescuento';
import FormValoradoExpediente from '../components/tramites/FormValoradoExpediente';
import RequisitosTramite from '../components/tramites/RequisitosTramite';
import SelectorDocumentos from '../components/tramites/SelectorDocumentos';
import SelectorEstudiante from '../components/tramites/SelectorEstudiante';
import { calcularImporteValorado } from '../components/tramites/calculosTramite';
const errorDe = (err) => err.response?.data?.errors ? Object.values(err.response.data.errors).flat().join(' ') : err.response?.data?.message || 'No se pudo registrar el trámite.';
const FORM_DESCUENTO = {
  unidad_postgrado: '', nombre_diplomado: '', gestion_diplomado: '', fecha_inicio_diplomado: '',
  nro_avance_academico: '', regimen: 'SEMESTRAL', anio_ultima_nota: '',
  periodo_ultima_nota: '', modulo_reprobado: '',
};
export default function NuevoTramite() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const esRecepcion = user?.rol === 'RECEPCION';
  const [catalogos, setCatalogos] = useState({ documentos: [], requisitos: [], carreras: [], estudiantes: [] });
  const [estudianteId, setEstudianteId] = useState('');
  const [tipo, setTipo] = useState('LEGALIZACION');
  const [form, setForm] = useState({ destino: 'NACIONAL', pais_destino: '', institucion_destino: '', finalidad: '', prioridad: 'NORMAL', carrera_id: user?.carrera_id ? String(user.carrera_id) : '' });
  const [seleccionados, setSeleccionados] = useState([]); const [descuento, setDescuento] = useState(FORM_DESCUENTO);
  const [archivoDescuento, setArchivoDescuento] = useState(null);
  const [error, setError] = useState(''); const [guardando, setGuardando] = useState(false); const [cargando, setCargando] = useState(true);
  useEffect(() => {
    obtenerCatalogos().then((res) => setCatalogos(res.data))
      .catch((err) => setError(errorDe(err))).finally(() => setCargando(false));
  }, []);
  const estudiante = catalogos.estudiantes.find((item) => String(item.id) === estudianteId);
  const carreraSolicitante = esRecepcion ? estudiante?.carrera_id : user?.carrera_id;
  const carreraRequerida = !form.carrera_id && !carreraSolicitante;
  const cambiarEstudiante = (id) => {
    const seleccionado = catalogos.estudiantes.find((item) => String(item.id) === id);
    setEstudianteId(id);
    setForm((prev) => ({ ...prev, carrera_id: seleccionado?.carrera_id ? String(seleccionado.carrera_id) : '' }));
  };
  const cambiarTipo = (nuevoTipo) => {
    setTipo(nuevoTipo);
    if (nuevoTipo === 'DESCUENTO_DIPLOMADO') setForm((prev) => ({ ...prev, destino: 'NACIONAL', pais_destino: '' }));
  };
  const guardar = async (event) => {
    event.preventDefault(); setError('');
    if (tipo === 'LEGALIZACION' && !seleccionados.length) { setError('Agregue al menos un documento.'); return; }
    if (esRecepcion && !estudianteId) { setError('Seleccione el estudiante solicitante.'); return; }
    if (carreraRequerida) { setError('Seleccione la carrera del solicitante.'); return; }
    const body = new FormData();
    body.append('tipo', tipo); body.append('destino', form.destino); body.append('prioridad', form.prioridad);
    if (esRecepcion) body.append('solicitante_id', estudianteId);
    if (form.carrera_id) body.append('carrera_id', form.carrera_id);
    ['pais_destino', 'institucion_destino', 'finalidad'].forEach((key) => { if (form[key]) body.append(key, form[key]); });
    if (tipo === 'LEGALIZACION') seleccionados.forEach((item, index) => {
      const doc = catalogos.documentos.find((entry) => Number(entry.id) === Number(item.catalogo_documento_id));
      body.append(`documentos[${index}][catalogo_documento_id]`, String(item.catalogo_documento_id));
      body.append(`documentos[${index}][cantidad_paginas]`, String(item.cantidad_paginas));
      if (doc.unidad_cobro === 'MATERIA') body.append(`documentos[${index}][cantidad_materias]`, String(item.cantidad_materias));
      if (esRecepcion) body.append(`documentos[${index}][firmas_verificadas]`, item.firmas_verificadas ? '1' : '0');
      if (item.nro_valorado) {
        body.append(`documentos[${index}][nro_valorado]`, item.nro_valorado);
        body.append(`documentos[${index}][importe_valorado]`, String(item.importe_valorado || calcularImporteValorado(doc, item)));
      }
      if (item.archivo) body.append(`archivos[${index}]`, item.archivo);
    });
    else {
      Object.entries(descuento).forEach(([key, value]) => { if (value !== '') body.append(`descuento[${key}]`, value); });
      if (archivoDescuento) body.append('archivos[0]', archivoDescuento);
    }
    setGuardando(true);
    try {
      const res = await crearTramite(body);
      navigate(esRecepcion ? '/tramites' : '/mis-tramites', { state: { mensaje: res.data.message } });
    }
    catch (err) { setError(errorDe(err)); } finally { setGuardando(false); }
  };
  return (
    <div className="max-w-5xl mx-auto space-y-5">
      <header><h1 className="text-xl font-bold text-slate-800">Registro de Trámite (CU3)</h1>
        <p className="text-sm text-slate-500">{esRecepcion ? 'Registre la solicitud en ventanilla.' : `Solicitante: ${user?.nombres} ${user?.apellidos}`}</p></header>
      {cargando ? <p className="text-sm text-slate-500">Cargando catálogos...</p> : <form onSubmit={guardar} className="space-y-5">
        {esRecepcion && <SelectorEstudiante estudiantes={catalogos.estudiantes} estudianteId={estudianteId} onChange={cambiarEstudiante} />}
        <DatosTramite form={form} setForm={setForm} tipo={tipo} setTipo={cambiarTipo} carreras={catalogos.carreras} carreraRequerida={carreraRequerida} />
        <RequisitosTramite requisitos={catalogos.requisitos} tipo={tipo} pais={form.destino === 'EXTERIOR' ? form.pais_destino : null} />
        {tipo === 'LEGALIZACION'
          ? <><SelectorDocumentos documentos={catalogos.documentos} seleccionados={seleccionados} onChange={setSeleccionados} />
            <FormValoradoExpediente tipo={tipo} documentos={catalogos.documentos} seleccionados={seleccionados} setSeleccionados={setSeleccionados} esRecepcion={esRecepcion} /></>
          : <><FormDescuento form={descuento} setForm={setDescuento} />
            <FormValoradoExpediente tipo={tipo} archivoDescuento={archivoDescuento} setArchivoDescuento={setArchivoDescuento} /></>}
        <div className="flex justify-end"><button type="submit" disabled={guardando} className="bg-blue-900 hover:bg-blue-800 text-white px-5 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50">
          {guardando ? 'Registrando...' : 'Registrar y compilar expediente'}
        </button></div>
        <Alerta mensaje={error} />
      </form>}
    </div>
  );
}
