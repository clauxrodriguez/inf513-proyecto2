import { useState } from 'react';
import Modal from '../ui/Modal';
import Alerta from '../ui/Alerta';

const ROLES = ['ADMINISTRADOR', 'VICERRECTOR', 'RECTORADO', 'RECEPCION', 'SUPLENTE', 'ESTUDIANTE'];
const CARGOS = ['VICERRECTOR', 'RECTOR', 'DECANO', 'ENCARGADO_LEGALIZACIONES', 'FUNCIONARIO_VENTANILLA'];

export default function ModalUsuario({ editandoId, form, setForm, carreras, error, guardando, onClose, onSubmit }) {
  const [errorLocal, setErrorLocal] = useState('');
  const handleRolChange = (nuevoRol) => {
    let cargo = '';
    if (nuevoRol === 'SUPLENTE') cargo = 'DECANO';
    else if (nuevoRol === 'VICERRECTOR') cargo = 'VICERRECTOR';
    else if (nuevoRol === 'RECTORADO') cargo = 'RECTOR';
    else if (nuevoRol === 'RECEPCION') cargo = 'FUNCIONARIO_VENTANILLA';
    else if (nuevoRol === 'ADMINISTRADOR') cargo = 'ENCARGADO_LEGALIZACIONES';
    setForm((prev) => ({ ...prev, rol: nuevoRol, cargo, registro: nuevoRol === 'ESTUDIANTE' ? prev.registro : '' }));
  };
  const submit = (event) => {
    setErrorLocal('');
    onSubmit(event).catch((err) => setErrorLocal(err.message));
  };
  const campo = (label, key, props = {}) => (
    <div>
      <label className="block font-medium text-slate-700 mb-1">{label}</label>
      <input {...props} value={form[key]} onChange={(e) => setForm({ ...form, [key]: e.target.value })}
        className="w-full border border-slate-300 rounded-lg px-3 py-1.5" />
    </div>
  );

  return (
    <Modal titulo={editandoId ? 'Editar Usuario' : 'Registrar Nuevo Usuario'}>
      <Alerta mensaje={error || errorLocal} className="mb-4 text-xs" />
      <form onSubmit={submit} autoComplete="off" className="space-y-3 text-sm">
        <div className="grid grid-cols-2 gap-3">
          {campo('Nombres *', 'nombres', { required: true })}
          {campo('Apellidos *', 'apellidos', { required: true })}
        </div>
        <div className="grid grid-cols-2 gap-3">
          {campo('C.I. *', 'ci', { required: true })}
          <div><label className="block font-medium text-slate-700 mb-1">Rol del Sistema *</label>
            <select value={form.rol} onChange={(e) => handleRolChange(e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-1.5 bg-white">
              {ROLES.map((r) => <option key={r} value={r}>{r}</option>)}
            </select>
          </div>
        </div>
        {form.rol === 'ESTUDIANTE' ? (
          <div className="grid grid-cols-2 gap-3">
            {campo('Registro Universitario', 'registro')}
            <div><label className="block font-medium text-slate-700 mb-1">Carrera</label>
              <select value={form.carrera_id} onChange={(e) => setForm({ ...form, carrera_id: e.target.value })} className="w-full border border-slate-300 rounded-lg px-3 py-1.5 bg-white">
                <option value="">Seleccione carrera...</option>{carreras.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
              </select>
            </div>
          </div>
        ) : (
          <div className="grid grid-cols-2 gap-3">
            <div><label className="block font-medium text-slate-700 mb-1">Cargo Institucional *</label>
              <select required value={form.cargo} onChange={(e) => setForm({ ...form, cargo: e.target.value })} className="w-full border border-slate-300 rounded-lg px-3 py-1.5 bg-white">
                <option value="">Seleccione cargo...</option>{CARGOS.map((c) => <option key={c} value={c}>{c}</option>)}
              </select>
            </div>
            <div><label className="block font-medium text-slate-700 mb-1">Carrera / Facultad {form.rol === 'SUPLENTE' ? '*' : '(Opcional)'}</label>
              <select required={form.rol === 'SUPLENTE'} value={form.carrera_id} onChange={(e) => setForm({ ...form, carrera_id: e.target.value })} className="w-full border border-slate-300 rounded-lg px-3 py-1.5 bg-white">
                <option value="">Administración Central</option>{carreras.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}
              </select>
            </div>
          </div>
        )}
        {campo('Correo Institucional *', 'email', { type: 'email', required: true })}
        <div className="grid grid-cols-2 gap-3">
          {campo('Teléfono', 'telefono', { autoComplete: 'off' })}
          {campo(`Contraseña ${editandoId ? '(dejar vacío para mantener)' : '*'}`, 'password', {
            type: 'password', required: !editandoId, minLength: 8, autoComplete: 'off',
          })}
        </div>
        <div className="flex justify-end gap-2 pt-3 border-t border-slate-200">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Cancelar</button>
          <button type="submit" disabled={guardando} className="px-4 py-2 rounded-lg bg-blue-900 hover:bg-blue-800 text-white font-medium disabled:opacity-50">{guardando ? 'Guardando...' : 'Guardar'}</button>
        </div>
      </form>
    </Modal>
  );
}
