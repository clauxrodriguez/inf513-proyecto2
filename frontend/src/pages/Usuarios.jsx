import { useEffect, useState } from 'react';
import { cambiarEstadoUsuario, crearUsuario, obtenerUsuarios, actualizarUsuario } from '../api/usuariosApi';
import TablaUsuarios from '../components/usuarios/TablaUsuarios';
import ModalUsuario from '../components/usuarios/ModalUsuario';

const FORM_INICIAL = {
  nombres: '', apellidos: '', ci: '', registro: '', email: '', telefono: '',
  direccion: '', password: '', rol: 'ESTUDIANTE', cargo: '', carrera_id: '', activo: true,
};

export default function Usuarios() {
  const [usuarios, setUsuarios] = useState([]);
  const [carreras, setCarreras] = useState([]);
  const [modalAbierto, setModalAbierto] = useState(false);
  const [editandoId, setEditandoId] = useState(null);
  const [form, setForm] = useState(FORM_INICIAL);
  const [error, setError] = useState('');
  const [guardando, setGuardando] = useState(false);
  const cargarDatos = async () => {
    const res = await obtenerUsuarios();
    setUsuarios(res.data.usuarios);
    setCarreras(res.data.carreras);
  };
  useEffect(() => { cargarDatos(); }, []);
  const abrirNuevo = () => {
    setEditandoId(null); setForm(FORM_INICIAL); setError(''); setModalAbierto(true);
  };
  const abrirEditar = (u) => {
    setEditandoId(u.id);
    setForm({
      nombres: u.nombres, apellidos: u.apellidos, ci: u.ci, registro: u.registro || '',
      email: u.email, telefono: u.telefono || '', direccion: u.direccion || '',
      password: '', rol: u.rol, cargo: u.cargo || '', carrera_id: u.carrera_id || '', activo: u.activo,
    });
    setError(''); setModalAbierto(true);
  };
  const guardar = async (event) => {
    event.preventDefault(); setError(''); setGuardando(true);
    const payload = {
      ...form, registro: form.registro || null, cargo: form.cargo || null,
      carrera_id: form.carrera_id ? Number(form.carrera_id) : null,
    };
    try {
      if (editandoId) await actualizarUsuario(editandoId, payload);
      else await crearUsuario(payload);
      setModalAbierto(false); await cargarDatos();
    } catch (err) {
      const detalles = err.response?.data?.errors;
      setError(detalles ? Object.values(detalles).flat().join(' ') : err.response?.data?.message || 'Error al guardar el usuario.');
    } finally { setGuardando(false); }
  };
  const toggleEstado = async (usuario) => {
    try { await cambiarEstadoUsuario(usuario.id); await cargarDatos(); }
    catch (err) { alert(err.response?.data?.message || 'No se pudo cambiar el estado.'); }
  };

  return (
    <div className="space-y-5">
      <TablaUsuarios usuarios={usuarios} onNuevo={abrirNuevo} onEditar={abrirEditar} onToggleEstado={toggleEstado} />
      {modalAbierto && <ModalUsuario editandoId={editandoId} form={form} setForm={setForm} carreras={carreras}
        error={error} guardando={guardando} onClose={() => setModalAbierto(false)} onSubmit={guardar} />}
    </div>
  );
}
