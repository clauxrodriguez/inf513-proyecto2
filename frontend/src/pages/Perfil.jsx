import { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import { actualizarPerfil } from '../api/usuariosApi';

export default function Perfil() {
  const { user } = useAuth();
  const [telefono, setTelefono] = useState(user?.telefono || '');
  const [direccion, setDireccion] = useState(user?.direccion || '');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [mensaje, setMensaje] = useState('');
  const [error, setError] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    setMensaje('');
    setError('');
    try {
      const res = await actualizarPerfil({
        telefono,
        direccion,
        password: password || null,
        password_confirmation: passwordConfirmation || null,
      });
      setMensaje(res.data.message);
      setPassword('');
      setPasswordConfirmation('');
    } catch (err) {
      const detalles = err.response?.data?.errors;
      setError(detalles ? Object.values(detalles).flat().join(' ') : 'Error al actualizar perfil.');
    }
  };

  return (
    <div className="max-w-xl bg-white rounded-xl border border-slate-200 p-6 shadow-sm">
      <h1 className="text-xl font-bold text-slate-800">Mi Perfil Institucional</h1>
      <p className="text-sm text-slate-500 mb-5">
        {user?.nombres} {user?.apellidos} — <span className="font-medium">{user?.rol}</span>
      </p>

      {mensaje && <div className="mb-4 p-3 rounded-lg bg-emerald-50 text-emerald-700 text-sm">{mensaje}</div>}
      {error && <div className="mb-4 p-3 rounded-lg bg-red-50 text-red-700 text-sm">{error}</div>}

      <form onSubmit={handleSubmit} className="space-y-4 text-sm">
        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="block font-medium text-slate-700 mb-1">C.I.</label>
            <input disabled value={user?.ci || ''} className="w-full border border-slate-200 bg-slate-50 rounded-lg px-3 py-2 text-slate-500" />
          </div>
          <div>
            <label className="block font-medium text-slate-700 mb-1">Correo Institucional</label>
            <input disabled value={user?.email || ''} className="w-full border border-slate-200 bg-slate-50 rounded-lg px-3 py-2 text-slate-500" />
          </div>
        </div>

        <div>
          <label className="block font-medium text-slate-700 mb-1">Teléfono</label>
          <input value={telefono} onChange={(e) => setTelefono(e.target.value)}
            className="w-full border border-slate-300 rounded-lg px-3 py-2" />
        </div>

        <div>
          <label className="block font-medium text-slate-700 mb-1">Dirección</label>
          <input value={direccion} onChange={(e) => setDireccion(e.target.value)}
            className="w-full border border-slate-300 rounded-lg px-3 py-2" />
        </div>

        <div className="pt-3 border-t border-slate-200 grid grid-cols-2 gap-3">
          <div>
            <label className="block font-medium text-slate-700 mb-1">Nueva Contraseña</label>
            <input type="password" minLength={8} value={password} onChange={(e) => setPassword(e.target.value)}
              placeholder="Opcional" className="w-full border border-slate-300 rounded-lg px-3 py-2" />
          </div>
          <div>
            <label className="block font-medium text-slate-700 mb-1">Confirmar Contraseña</label>
            <input type="password" minLength={8} value={passwordConfirmation} onChange={(e) => setPasswordConfirmation(e.target.value)}
              placeholder="Opcional" className="w-full border border-slate-300 rounded-lg px-3 py-2" />
          </div>
        </div>

        <button type="submit" className="bg-blue-900 hover:bg-blue-800 text-white font-medium px-5 py-2 rounded-lg transition">
          Guardar Cambios
        </button>
      </form>
    </div>
  );
}