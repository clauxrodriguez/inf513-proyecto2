import { BarChart3 } from 'lucide-react';

export default function TarjetasIndicadores({ indicadores = {} }) {
  const tarjetas = [
    { titulo: 'Ingresados vs. Despachados', valor: <>{indicadores.total_tramites ?? 0} / <span className="text-emerald-700">{indicadores.despachados ?? 0}</span></> },
    { titulo: 'Nacional vs. Exterior (Apostilla)', valor: <>{indicadores.nacional ?? 0} / <span className="text-blue-800">{indicadores.exterior ?? 0}</span></> },
    { titulo: 'Cumplimiento SLA (Meta 72h)', valor: <span className="text-emerald-700">{indicadores.cumplimiento_sla ?? 100}%<span className="text-xs font-normal text-red-600 ml-2">({indicadores.sla_vencidos ?? 0} vencidos)</span></span> },
    { titulo: 'Descuento Diplomado (Res. 01-28)', valor: indicadores.descuento_diplomado ?? 0 },
  ];
  return (
    <section>
      <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-1.5">
        <BarChart3 className="w-4 h-4 text-blue-800" />Indicadores de Despacho en Tiempo Real
      </h2>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {tarjetas.map(({ titulo, valor }) => (
          <div key={titulo} className="bg-white p-4 rounded-xl border border-slate-200">
            <p className="text-xs text-slate-500">{titulo}</p>
            <p className="text-xl font-bold text-slate-800 mt-1">{valor}</p>
          </div>
        ))}
      </div>
    </section>
  );
}
