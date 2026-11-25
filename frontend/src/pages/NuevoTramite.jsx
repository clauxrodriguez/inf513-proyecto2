import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { crearTramite, obtenerCatalogos } from '../api/tramitesApi';
import { useAuth } from '../context/AuthContext';
import Alerta from '../components/ui/Alerta';
import DatosTramite from '../components/tramites/DatosTramite';
import FormDescuento from '../components/tramites/FormDescuento';
import RequisitosTramite from '../components/tramites/RequisitosTramite';
import SelectorDocumentos from '../components/tramites/SelectorDocumentos';
import { mensajeErrorApi } from '../components/tramites/calculosTramite';

const FORM_INICIAL = { destino: 'NACIONAL', pais_destino: '', institucion_destino: '', finalidad: '', prioridad: 'NORMAL', carrera_id: '' };
const DESCUENTO_INICIAL = { unidad_postgrado: '', nombre_diplomado: '', nro_avance_academico: '', fecha_ultima_nota: '' };
const MAXIMO_RESPALDO = 10 * 1024 * 1024;

/** Validación del cliente: los mismos criterios que RegistrarTramiteRequest, con mensajes en español. */
function validar({ tipo, form, seleccionados, documentos, carreras, descuento, respaldo }) {
  if (carreras.length > 1 && !form.carrera_id) return 'Seleccione la carrera a la que corresponde el trámite.';
  if (!carreras.length) return 'Su cuenta no tiene una carrera asignada. Consulte con la Unidad de Legalizaciones.';
  if (tipo === 'LEGALIZACION') {
    if (form.destino === 'EXTERIOR' && !form.pais_destino.trim()) return 'Indique el país de destino.';
    if (!seleccionados.length) return 'Agregue al menos un documento a legalizar.';
    for (const item of seleccionados) {
      const documento = documentos.find((doc) => Number(doc.id) === Number(item.catalogo_documento_id));
      if (!(Number(item.cantidad_paginas) >= 1)) return `Indique la cantidad de páginas de «${documento?.nombre}».`;
      if (documento?.tipo_cobro === 'MATERIA' && !(Number(item.cantidad_materias) >= 1)) return `Indique la cantidad de materias de «${documento.nombre}».`;
    }
    return '';
  }
  if (Object.values(descuento).some((valor) => !String(valor).trim())) return 'Complete todos los datos de la solicitud de descuento.';
  if (descuento.fecha_ultima_nota > new Date().toISOString().slice(0, 10)) return 'La fecha de la última nota no puede ser posterior a hoy.';
  if (respaldo && respaldo.type !== 'application/pdf') return 'El Avance Académico debe ser un archivo PDF.';
  if (respaldo && respaldo.size > MAXIMO_RESPALDO) return 'El Avance Académico no puede superar los 10 MB.';
  return '';
}

export default function NuevoTramite() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [catalogos, setCatalogos] = useState({ documentos: [], requisitos: [], carreras: [] });
  const [tipo, setTipo] = useState('LEGALIZACION');
  const [form, setForm] = useState(FORM_INICIAL);
  const [seleccionados, setSeleccionados] = useState([]);
  const [descuento, setDescuento] = useState(DESCUENTO_INICIAL);
  const [respaldo, setRespaldo] = useState(null);
  const [error, setError] = useState('');
  const [guardando, setGuardando] = useState(false);
  const [cargando, setCargando] = useState(true);

  useEffect(() => {
    obtenerCatalogos()
      .then((res) => setCatalogos(res.data))
      .catch((err) => setError(mensajeErrorApi(err, 'No se pudieron cargar los catálogos.')))
      .finally(() => setCargando(false));
  }, []);

  const guardar = async (event) => {
    event.preventDefault();
    const fallo = validar({ tipo, form, seleccionados, documentos: catalogos.documentos, carreras: catalogos.carreras, descuento, respaldo });
    setError(fallo);
    if (fallo) return;

    const body = new FormData();
    body.append('tipo', tipo);
    body.append('prioridad', form.prioridad);
    if (form.carrera_id) body.append('carrera_id', form.carrera_id);
    ['institucion_destino', 'finalidad'].forEach((clave) => { if (form[clave].trim()) body.append(clave, form[clave].trim()); });
    if (tipo === 'LEGALIZACION') {
      body.append('destino', form.destino);
      if (form.destino === 'EXTERIOR') body.append('pais_destino', form.pais_destino.trim());
      seleccionados.forEach((item, indice) => {
        body.append(`documentos[${indice}][catalogo_documento_id]`, String(item.catalogo_documento_id));
        body.append(`documentos[${indice}][cantidad_paginas]`, String(item.cantidad_paginas));
        if (item.cantidad_materias) body.append(`documentos[${indice}][cantidad_materias]`, String(item.cantidad_materias));
      });
    } else {
      Object.entries(descuento).forEach(([clave, valor]) => body.append(`descuento[${clave}]`, String(valor).trim()));
      if (respaldo) body.append('respaldo', respaldo);
    }

    setGuardando(true);
    try {
      const res = await crearTramite(body);
      navigate('/mis-tramites', { state: { mensaje: res.data.message } });
    } catch (err) {
      setError(mensajeErrorApi(err, 'No se pudo registrar el trámite.'));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <div className="max-w-5xl mx-auto space-y-5">
      <header>
        <h1 className="text-xl font-bold text-slate-800">Solicitar trámite</h1>
        <p className="text-sm text-slate-500">Solicitante: {user?.nombres} {user?.apellidos}</p>
      </header>
      {cargando ? <p className="text-sm text-slate-500">Cargando catálogos...</p> : (
        <form onSubmit={guardar} noValidate className="space-y-5">
          <DatosTramite form={form} setForm={setForm} tipo={tipo} setTipo={setTipo} carreras={catalogos.carreras} />
          <RequisitosTramite requisitos={catalogos.requisitos} tipo={tipo} />
          {tipo === 'LEGALIZACION'
            ? <SelectorDocumentos documentos={catalogos.documentos} seleccionados={seleccionados} onChange={setSeleccionados} />
            : <FormDescuento form={descuento} setForm={setDescuento} archivo={respaldo} setArchivo={setRespaldo} />}
          <Alerta mensaje={error} />
          <div className="flex justify-end">
            <button type="submit" disabled={guardando} className="bg-blue-900 hover:bg-blue-800 text-white px-5 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50">
              {guardando ? 'Enviando solicitud...' : 'Enviar solicitud'}
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
