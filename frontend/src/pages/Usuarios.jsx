import { useCallback, useEffect, useState } from 'react';
import { actualizarUsuario, cambiarEstadoUsuario, crearUsuario, obtenerUsuarios } from '../api/usuariosApi';
import TablaUsuarios from '../components/usuarios/TablaUsuarios';
import ModalUsuario from '../components/usuarios/ModalUsuario';
import { mensajeErrorApi } from '../components/tramites/calculosTramite';
import Alerta from '../components/ui/Alerta';

const FORM_INICIAL = {
  nombres: '', apellidos: '', ci: '', registro: '', email: '', telefono: '', password: '', cargo: '', activo: true, roles: ['ESTUDIANTE'], carreras: [],
};
const CON_CARRERA = ['ESTUDIANTE', 'DIRECTOR_CARRERA', 'DECANO'];

/** Validación del cliente (mismas reglas que GuardarUsuarioRequest y UsuarioService). */
function validar(form, editando) {
  if (!form.nombres.trim() || !form.apellidos.trim() || !form.ci.trim() || !form.email.trim()) return 'Complete nombres, apellidos, C.I. y correo.';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) return 'El correo no tiene un formato válido.';
  if (!editando && form.password.length < 8) return 'La contraseña debe tener al menos 8 caracteres.';
  if (editando && form.password && form.password.length < 8) return 'La nueva contraseña debe tener al menos 8 caracteres.';
  if (!form.roles.length) return 'Asigne al menos un rol al usuario.';
  const esEstudiante = form.roles.includes('ESTUDIANTE');
  if (esEstudiante && form.roles.length > 1) return 'El rol Estudiante no puede combinarse con roles administrativos.';
  if (esEstudiante && !form.registro.trim()) return 'Un estudiante debe tener Registro Universitario.';
  if (!esEstudiante && !form.cargo.trim()) return 'Indique el cargo institucional.';
  if (form.roles.some((rol) => CON_CARRERA.includes(rol)) && !form.carreras.length) return 'Asigne al menos una carrera para este rol.';
  return '';
}

export default function Usuarios() {
  const [datos, setDatos] = useState({ usuarios: [], carreras: [], roles: [] });
  const [modalAbierto, setModalAbierto] = useState(false);
  const [editandoId, setEditandoId] = useState(null);
  const [form, setForm] = useState(FORM_INICIAL);
  const [error, setError] = useState('');
  const [mensaje, setMensaje] = useState('');
  const [guardando, setGuardando] = useState(false);

  const cargar = useCallback(async () => {
    try {
      setDatos((await obtenerUsuarios()).data);
    } catch (err) {
      setMensaje('');
      setError(mensajeErrorApi(err, 'No se pudieron cargar los usuarios.'));
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const abrirNuevo = () => { setEditandoId(null); setForm(FORM_INICIAL); setError(''); setModalAbierto(true); };
  const abrirEditar = (u) => {
    setEditandoId(u.id);
    setForm({
      nombres: u.nombres, apellidos: u.apellidos, ci: u.ci, registro: u.registro || '', email: u.email, telefono: u.telefono || '',
      password: '', cargo: u.cargo || '', activo: u.activo, roles: u.roles, carreras: u.carreras,
    });
    setError('');
    setModalAbierto(true);
  };

  const guardar = async (event) => {
    event.preventDefault();
    const fallo = validar(form, Boolean(editandoId));
    setError(fallo);
    if (fallo) return;
    setGuardando(true);
    const payload = {
      ...form,
      registro: form.registro.trim() || null,
      cargo: form.cargo.trim() || null,
      telefono: form.telefono.trim() || null,
      password: form.password || null,
    };
    try {
      const res = editandoId ? await actualizarUsuario(editandoId, payload) : await crearUsuario(payload);
      setModalAbierto(false);
      setMensaje(res.data.message);
      await cargar();
    } catch (err) {
      setError(mensajeErrorApi(err, 'No se pudo guardar el usuario.'));
    } finally {
      setGuardando(false);
    }
  };

  const alternarEstado = async (usuario) => {
    try {
      setMensaje((await cambiarEstadoUsuario(usuario.id)).data.message);
      setError('');
      await cargar();
    } catch (err) {
      setMensaje('');
      setError(mensajeErrorApi(err, 'No se pudo cambiar el estado.'));
    }
  };

  return (
    <div className="space-y-5">
      <Alerta tipo="exito" mensaje={mensaje} />
      {!modalAbierto && <Alerta mensaje={error} />}
      <TablaUsuarios usuarios={datos.usuarios} roles={datos.roles} onNuevo={abrirNuevo} onEditar={abrirEditar} onToggleEstado={alternarEstado} />
      {modalAbierto && (
        <ModalUsuario editandoId={editandoId} form={form} setForm={setForm} carreras={datos.carreras} roles={datos.roles}
          error={error} guardando={guardando} onClose={() => setModalAbierto(false)} onSubmit={guardar} />
      )}
    </div>
  );
}
