import { useState } from 'react';

export default function SelectorEstudiante({ estudiantes, estudianteId, onChange }) {
  const [busqueda, setBusqueda] = useState('');
  const filtrados = estudiantes.filter((estudiante) => (
    `${estudiante.nombres} ${estudiante.apellidos} ${estudiante.ci} ${estudiante.registro || ''}`
      .toLowerCase().includes(busqueda.toLowerCase())
  ));

  return (
    <div className="bg-white p-5 rounded-xl border border-slate-200 space-y-3">
      <h2 className="font-bold text-slate-800">Estudiante solicitante</h2>
      <label className="block text-sm font-medium text-slate-700" htmlFor="buscar-estudiante">Buscar por nombre, C.I. o registro</label>
      <input id="buscar-estudiante" value={busqueda} onChange={(e) => setBusqueda(e.target.value)}
        placeholder="Escriba para buscar..." autoComplete="off"
        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
      <select required value={estudianteId} onChange={(e) => onChange(e.target.value)}
        className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
        <option value="">Seleccione un estudiante...</option>
        {filtrados.map((estudiante) => (
          <option key={estudiante.id} value={estudiante.id}>
            {estudiante.apellidos}, {estudiante.nombres} — C.I. {estudiante.ci}
            {estudiante.registro ? ` — ${estudiante.registro}` : ''}
          </option>
        ))}
      </select>
    </div>
  );
}
