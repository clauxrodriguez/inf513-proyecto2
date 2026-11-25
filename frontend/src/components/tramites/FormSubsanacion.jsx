import { useState } from 'react';

const TIPOS_PERMITIDOS = ['application/pdf', 'image/jpeg', 'image/png'];
const MAXIMO = 10 * 1024 * 1024;

/** Subsanación de una observación; el respaldo es opcional (en origen la corrige la unidad emisora). */
export default function FormSubsanacion({ onSubmit, disabled, conRespaldo }) {
  const [archivo, setArchivo] = useState(null);
  const [error, setError] = useState('');

  const guardar = async (event) => {
    event.preventDefault();
    if (archivo && !TIPOS_PERMITIDOS.includes(archivo.type)) { setError('El respaldo debe ser un PDF o una imagen JPG o PNG.'); return; }
    if (archivo && archivo.size > MAXIMO) { setError('El respaldo no puede superar los 10 MB.'); return; }
    setError('');
    const body = new FormData();
    if (archivo) body.append('archivo', archivo);
    if (await onSubmit(body)) setArchivo(null);
  };

  return (
    <form onSubmit={guardar} noValidate className="mt-3 flex flex-wrap items-end gap-3">
      {conRespaldo && (
        <label className="min-w-[220px] flex-1 text-xs font-medium text-slate-600">
          Respaldo corregido (opcional: PDF o imagen, máximo 10 MB)
          <input type="file" accept="application/pdf,image/jpeg,image/png" onChange={(event) => setArchivo(event.target.files?.[0] || null)}
            className="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 text-sm" />
        </label>
      )}
      <button type="submit" disabled={disabled} className="rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
        Marcar como subsanada
      </button>
      {error && <p role="alert" className="w-full text-xs text-red-700">{error}</p>}
    </form>
  );
}
