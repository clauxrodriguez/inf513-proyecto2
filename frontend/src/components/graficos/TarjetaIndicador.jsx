import { CircleAlert, CircleCheck, Minus } from 'lucide-react';

const UNIDAD = { '%': (v) => `${v}%`, HORAS: (v) => `${v} h`, BS: (v) => `Bs ${Number(v).toLocaleString('es-BO', { minimumFractionDigits: 2 })}`, CANTIDAD: (v) => v };

/** Indicador de gestión con su meta: el estado se comunica con ícono y texto, nunca solo con color. */
export default function TarjetaIndicador({ indicador }) {
  const formato = UNIDAD[indicador.unidad_medida] || ((v) => v);
  const estado = indicador.cumple === null
    ? { Icono: Minus, texto: indicador.meta === null ? 'Sin meta definida' : 'Sin datos', clase: 'text-slate-500' }
    : indicador.cumple
      ? { Icono: CircleCheck, texto: 'Cumple la meta', clase: 'text-emerald-700' }
      : { Icono: CircleAlert, texto: 'Bajo la meta', clase: 'text-red-700' };

  return (
    <article className="rounded-xl border border-slate-200 bg-white p-4">
      <h3 className="text-xs text-slate-500">{indicador.nombre}</h3>
      <p className="mt-1 text-2xl font-bold text-slate-800">{indicador.valor === null ? '—' : formato(indicador.valor)}</p>
      <p className={`mt-1 flex items-center gap-1 text-xs font-medium ${estado.clase}`}>
        <estado.Icono className="h-3.5 w-3.5" aria-hidden="true" />{estado.texto}
        {indicador.meta !== null && <span className="font-normal text-slate-500">· meta {formato(Number(indicador.meta))}</span>}
      </p>
    </article>
  );
}
