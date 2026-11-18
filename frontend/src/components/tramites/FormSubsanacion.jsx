import { useState } from 'react';

export default function FormSubsanacion({ onSubmit, disabled }) {
  const [archivo, setArchivo] = useState(null);

  const guardar = async (event) => {
    event.preventDefault();
    if (!archivo) return;
    const body = new FormData();
    body.append('archivo', archivo);
    const guardado = await onSubmit(body);
    if (guardado) setArchivo(null);
  };

  return (
    <form onSubmit={guardar} className="mt-3 flex flex-wrap items-end gap-3">
      <label className="min-w-[220px] flex-1 text-xs font-medium text-slate-600">
        Respaldo corregido (PDF o imagen, máximo 10 MB)
        <input required type="file" accept="application/pdf,image/jpeg,image/png" onChange={(event) => setArchivo(event.target.files?.[0] || null)}
          className="mt-1 block w-full rounded-lg border border-slate-300 bg-white p-2 text-sm" />
      </label>
      <button type="submit" disabled={disabled || !archivo} className="rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">
        Enviar subsanación
      </button>
    </form>
  );
}
