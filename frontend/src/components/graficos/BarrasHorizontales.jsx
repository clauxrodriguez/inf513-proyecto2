/**
 * Barras horizontales de un solo tono (magnitud): etiqueta y valor rotulados en cada fila,
 * extremo de dato redondeado (4px) anclado a la línea base, separación de 2px entre barras.
 * El tooltip (title) amplía el detalle al pasar el puntero o enfocar la fila.
 */
export default function BarrasHorizontales({ titulo, descripcion, datos, formato = (v) => v, vacio = 'Sin datos en el periodo.' }) {
  const maximo = Math.max(1, ...datos.map((d) => Number(d.valor) || 0));

  return (
    <figure className="rounded-xl border border-slate-200 bg-white p-4">
      <figcaption className="mb-3">
        <h3 className="text-sm font-semibold text-slate-800">{titulo}</h3>
        {descripcion && <p className="text-xs text-slate-500">{descripcion}</p>}
      </figcaption>
      {datos.length ? (
        <ul className="space-y-0.5" aria-label={titulo}>
          {datos.map((d) => {
            const ancho = `${Math.max(1.5, ((Number(d.valor) || 0) / maximo) * 100)}%`;
            return (
              <li key={d.etiqueta} tabIndex={0} title={d.detalle ? `${d.etiqueta}: ${formato(d.valor)} · ${d.detalle}` : `${d.etiqueta}: ${formato(d.valor)}`}
                className="group grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_auto] items-center gap-2 rounded py-1 text-xs hover:bg-slate-50 focus:bg-slate-50">
                <span className="truncate text-slate-600">{d.etiqueta}</span>
                <span className="h-3 border-l border-[var(--grafico-eje)]">
                  <span className="block h-3 rounded-r-[4px] bg-[var(--serie-1)] opacity-90 transition-opacity group-hover:opacity-100" style={{ width: ancho }} />
                </span>
                <span className="font-medium tabular-nums text-slate-800">{formato(d.valor)}</span>
              </li>
            );
          })}
        </ul>
      ) : <p className="text-sm text-slate-500">{vacio}</p>}
    </figure>
  );
}
