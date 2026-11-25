import { useState } from 'react';

const ANCHO = 640;
const ALTO = 200;
const MARGEN = { arriba: 12, derecha: 12, abajo: 26, izquierda: 34 };

const diaCorto = (fecha) => new Date(`${String(fecha).slice(0, 10)}T00:00:00`).toLocaleDateString('es-BO', { day: '2-digit', month: 'short' });

/**
 * Líneas por día (un solo eje y): trazo de 2px, leyenda siempre visible, retícula tenue
 * y guía vertical con tooltip al pasar el puntero (o al recorrer con las flechas del teclado).
 */
export default function LineasDiarias({ titulo, descripcion, datos, series }) {
  const [indice, setIndice] = useState(null);
  const internoAncho = ANCHO - MARGEN.izquierda - MARGEN.derecha;
  const internoAlto = ALTO - MARGEN.arriba - MARGEN.abajo;
  const maximo = Math.max(1, ...datos.flatMap((d) => series.map((s) => Number(d[s.clave]) || 0)));
  const paso = Math.max(1, Math.ceil(maximo / 4));
  const tope = paso * 4;
  const x = (i) => MARGEN.izquierda + (datos.length <= 1 ? internoAncho / 2 : (i * internoAncho) / (datos.length - 1));
  const y = (valor) => MARGEN.arriba + internoAlto - ((Number(valor) || 0) / tope) * internoAlto;
  const cadaEtiqueta = Math.max(1, Math.ceil(datos.length / 7));

  const mover = (event) => {
    const caja = event.currentTarget.getBoundingClientRect();
    const posicion = ((event.clientX - caja.left) / caja.width) * ANCHO;
    const relativo = (posicion - MARGEN.izquierda) / internoAncho;
    setIndice(Math.min(datos.length - 1, Math.max(0, Math.round(relativo * (datos.length - 1)))));
  };
  const teclado = (event) => {
    if (event.key === 'ArrowRight') setIndice((i) => Math.min(datos.length - 1, (i ?? -1) + 1));
    if (event.key === 'ArrowLeft') setIndice((i) => Math.max(0, (i ?? datos.length) - 1));
    if (event.key === 'Escape') setIndice(null);
  };
  const punto = indice !== null ? datos[indice] : null;

  return (
    <figure className="rounded-xl border border-slate-200 bg-white p-4">
      <figcaption className="mb-2 flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold text-slate-800">{titulo}</h3>
          {descripcion && <p className="text-xs text-slate-500">{descripcion}</p>}
        </div>
        <ul className="flex flex-wrap gap-3 text-xs text-slate-600" aria-label="Leyenda">
          {series.map((s) => (
            <li key={s.clave} className="flex items-center gap-1.5">
              <span className="inline-block h-0.5 w-4 rounded" style={{ backgroundColor: s.color }} aria-hidden="true" />{s.nombre}
            </li>
          ))}
        </ul>
      </figcaption>
      <div className="relative">
        <svg viewBox={`0 0 ${ANCHO} ${ALTO}`} className="h-auto w-full" role="img" tabIndex={0}
          aria-label={`${titulo}. Use las flechas izquierda y derecha para recorrer los días.`}
          onMouseMove={mover} onMouseLeave={() => setIndice(null)} onKeyDown={teclado} onBlur={() => setIndice(null)}>
          {[0, 1, 2, 3, 4].map((n) => (
            <g key={n}>
              <line x1={MARGEN.izquierda} x2={ANCHO - MARGEN.derecha} y1={y(n * paso)} y2={y(n * paso)}
                stroke={n === 0 ? 'var(--grafico-eje)' : 'var(--grafico-grilla)'} strokeWidth="1" />
              <text x={MARGEN.izquierda - 6} y={y(n * paso) + 3} textAnchor="end" className="fill-slate-400 text-[10px] tabular-nums">{n * paso}</text>
            </g>
          ))}
          {datos.map((d, i) => (i % cadaEtiqueta === 0 || i === datos.length - 1) && (
            <text key={d.dia} x={x(i)} y={ALTO - 8} textAnchor="middle" className="fill-slate-400 text-[10px]">{diaCorto(d.dia)}</text>
          ))}
          {series.map((s) => (
            <polyline key={s.clave} fill="none" stroke={s.color} strokeWidth="2" strokeLinejoin="round" strokeLinecap="round"
              points={datos.map((d, i) => `${x(i)},${y(d[s.clave])}`).join(' ')} />
          ))}
          {punto && (
            <g>
              <line x1={x(indice)} x2={x(indice)} y1={MARGEN.arriba} y2={MARGEN.arriba + internoAlto} stroke="var(--grafico-eje)" strokeDasharray="3 3" />
              {series.map((s) => (
                <circle key={s.clave} cx={x(indice)} cy={y(punto[s.clave])} r="4.5" fill={s.color} stroke="var(--grafico-superficie)" strokeWidth="2" />
              ))}
            </g>
          )}
        </svg>
        {punto && (
          <div role="status" className="pointer-events-none absolute top-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg"
            style={{ left: `${Math.min(70, (x(indice) / ANCHO) * 100)}%` }}>
            <p className="font-semibold text-slate-800">{diaCorto(punto.dia)}</p>
            {series.map((s) => (
              <p key={s.clave} className="flex items-center gap-1.5 text-slate-600">
                <span className="inline-block h-2 w-2 rounded-full" style={{ backgroundColor: s.color }} aria-hidden="true" />
                {s.nombre}: <span className="font-medium tabular-nums text-slate-800">{punto[s.clave]}</span>
              </p>
            ))}
          </div>
        )}
      </div>
    </figure>
  );
}
