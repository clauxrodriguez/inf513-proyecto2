const TARJETAS = [
  ['en_curso', 'En curso', 'text-slate-800', 'border-slate-200'],
  ['verde', 'Verde · normal', 'text-emerald-700', 'border-emerald-200'],
  ['amarillo', 'Amarillo · alerta', 'text-amber-700', 'border-amber-200'],
  ['rojo', 'Rojo · vencido', 'text-red-700', 'border-red-200'],
  ['detenidos', 'Detenidos (observados)', 'text-slate-600', 'border-slate-200'],
];

/** Resumen del semáforo SLA (se muestra en el Dashboard del personal sin acceso a los reportes). */
export default function TarjetasResumenSla({ resumen }) {
  const cumplimiento = resumen.concluidos > 0 ? Math.round((resumen.concluidos_en_plazo * 1000) / resumen.concluidos) / 10 : null;
  return (
    <section className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
      {TARJETAS.map(([clave, etiqueta, color, borde]) => (
        <div key={clave} className={`rounded-xl border bg-white p-4 ${borde}`}>
          <p className="text-xs uppercase text-slate-500">{etiqueta}</p>
          <p className={`mt-1 text-2xl font-bold ${color}`}>{resumen[clave]}</p>
        </div>
      ))}
      <div className="rounded-xl border border-blue-200 bg-white p-4">
        <p className="text-xs uppercase text-slate-500">Concluidos en plazo</p>
        <p className="mt-1 text-2xl font-bold text-blue-900">{cumplimiento === null ? '—' : `${cumplimiento}%`}</p>
        <p className="text-xs text-slate-500">
          {resumen.concluidos_en_plazo}/{resumen.concluidos}{resumen.promedio_horas_concluidos !== null ? ` · prom. ${resumen.promedio_horas_concluidos} h` : ''}
        </p>
      </div>
    </section>
  );
}
