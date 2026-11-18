import { ShieldAlert } from 'lucide-react';

export default function Alerta({ tipo = 'error', mensaje, className = '' }) {
  if (!mensaje) return null;

  const estilos = tipo === 'exito'
    ? 'bg-emerald-50 border-emerald-200 text-emerald-700'
    : 'bg-red-50 border-red-200 text-red-700';

  return (
    <div className={`p-3 rounded-lg border text-sm ${estilos} ${className}`}>
      {tipo === 'error' && <ShieldAlert className="w-4 h-4 shrink-0 inline-block mr-2 align-middle" />}
      <span>{mensaje}</span>
    </div>
  );
}
