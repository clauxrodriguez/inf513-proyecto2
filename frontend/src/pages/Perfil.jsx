import { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { actualizarPerfil } from '../api/usuariosApi';
import { mensajeErrorApi } from '../components/tramites/calculosTramite';
import Alerta from '../components/ui/Alerta';

export default function Perfil() {
  const { user, roles } = useAuth();
  const [telefono, setTelefono] = useState(user?.telefono || '');
  const [password, setPassword] = useState('');
  const [confirmacion, setConfirmacion] = useState('');
  const [mensaje, setMensaje] = useState('');
  const [error, setError] = useState('');
  const [guardando, setGuardando] = useState(false);

  const guardar = async (e) => {
    e.preventDefault();
    setMensaje('');
    if (password && password.length < 8) { setError('La nueva contraseña debe tener al menos 8 caracteres.'); return; }
    if (password !== confirmacion) { setError('La confirmación de la contraseña no coincide.'); return; }
    setError('');
    setGuardando(true);
    try {
      const res = await actualizarPerfil({ telefono: telefono.trim() || null, password: password || null, password_confirmation: confirmacion || null });
      setMensaje(res.data.message);
      setPassword('');
      setConfirmacion('');
    } catch (err) {
      setError(mensajeErrorApi(err, 'No se pudo actualizar el perfil.'));
    } finally {
      setGuardando(false);
    }
  };

  return (
    <div className="max-w-xl bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
      <h1 className="text-xl font-bold text-slate-800">Mi perfil</h1>
      <p className="text-sm text-slate-500 mb-5">
        {user?.nombres} {user?.apellidos} — <span className="font-medium">{roles.map((r) => r.nombre).join(', ')}</span>
        {user?.cargo && <span> · {user.cargo}</span>}
      </p>

      <form onSubmit={guardar} noValidate className="space-y-4 text-sm">
        <div className="grid grid-cols-2 gap-3">
          <label className="block font-medium text-slate-700">C.I.
            <input disabled value={user?.ci || ''} className="mt-1 w-full border border-slate-200 bg-slate-50 rounded-lg px-3 py-2 text-slate-500" />
          </label>
          <label className="block font-medium text-slate-700">Correo institucional
            <input disabled value={user?.email || ''} className="mt-1 w-full border border-slate-200 bg-slate-50 rounded-lg px-3 py-2 text-slate-500" />
          </label>
        </div>
        <label className="block font-medium text-slate-700">Teléfono
          <input maxLength={30} value={telefono} onChange={(e) => setTelefono(e.target.value)} className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2" />
        </label>
        <div className="pt-3 border-t border-slate-200 grid grid-cols-2 gap-3">
          <label className="block font-medium text-slate-700">Nueva contraseña
            <input type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Opcional" className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2" />
          </label>
          <label className="block font-medium text-slate-700">Confirmar contraseña
            <input type="password" autoComplete="new-password" value={confirmacion} onChange={(e) => setConfirmacion(e.target.value)} placeholder="Opcional" className="mt-1 w-full border border-slate-300 rounded-lg px-3 py-2" />
          </label>
        </div>
        <Alerta tipo="exito" mensaje={mensaje} />
        <Alerta mensaje={error} />
        <button type="submit" disabled={guardando} className="bg-blue-900 hover:bg-blue-800 text-white font-medium px-5 py-2 rounded-lg transition disabled:opacity-50">
          {guardando ? 'Guardando...' : 'Guardar cambios'}
        </button>
      </form>
    </div>
  );
}
