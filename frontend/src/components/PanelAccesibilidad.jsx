import { useEffect, useRef, useState } from 'react';
import { Contrast, Minus, Moon, Palette, Plus, RotateCcw, Sun, SunMoon } from 'lucide-react';
import { ESCALA_MAXIMA, ESCALA_MINIMA, usePreferencias } from '../context/PreferenciasContext';

const TEMAS = [['ninos', 'Niños'], ['jovenes', 'Jóvenes'], ['adultos', 'Adultos']];
const MODOS = [['dia', 'Día', Sun], ['noche', 'Noche', Moon], ['auto', 'Automático', SunMoon]];

function Opcion({ activa, onClick, children }) {
  return (
    <button type="button" onClick={onClick} aria-pressed={activa}
      className={`flex-1 rounded-lg border px-2 py-1.5 text-xs font-medium ${activa ? 'border-blue-800 bg-blue-50 text-blue-900' : 'border-slate-300 text-slate-600 hover:bg-slate-50'}`}>
      {children}
    </button>
  );
}

/** Selector de tema, modo día/noche, tamaño de texto y alto contraste (botón en el encabezado). */
export default function PanelAccesibilidad({ claro = false }) {
  const { tema, modo, modoEfectivo, escala, altoContraste, actualizar, cambiarEscala, restablecer } = usePreferencias();
  const [abierto, setAbierto] = useState(false);
  const contenedor = useRef(null);

  useEffect(() => {
    if (!abierto) return undefined;
    const cerrar = (event) => { if (!contenedor.current?.contains(event.target)) setAbierto(false); };
    const escape = (event) => { if (event.key === 'Escape') setAbierto(false); };
    document.addEventListener('mousedown', cerrar);
    document.addEventListener('keydown', escape);
    return () => { document.removeEventListener('mousedown', cerrar); document.removeEventListener('keydown', escape); };
  }, [abierto]);

  return (
    <div ref={contenedor} className="relative">
      <button type="button" onClick={() => setAbierto((v) => !v)} aria-expanded={abierto} aria-label="Apariencia y accesibilidad"
        className={`rounded-lg p-2 ${claro ? 'text-blue-200 hover:bg-blue-900 hover:text-white' : 'text-slate-600 hover:bg-slate-100'}`}>
        <Palette className="h-5 w-5" aria-hidden="true" />
      </button>
      {abierto && (
        <div role="dialog" aria-label="Apariencia y accesibilidad" className="absolute right-0 z-50 mt-2 w-72 space-y-4 rounded-xl border border-slate-200 bg-white p-4 text-slate-800 shadow-xl">
          <fieldset>
            <legend className="mb-1.5 text-xs font-semibold uppercase text-slate-500">Tema</legend>
            <div className="flex gap-1.5">
              {TEMAS.map(([valor, etiqueta]) => <Opcion key={valor} activa={tema === valor} onClick={() => actualizar({ tema: valor })}>{etiqueta}</Opcion>)}
            </div>
          </fieldset>
          <fieldset>
            <legend className="mb-1.5 text-xs font-semibold uppercase text-slate-500">Modo {modo === 'auto' && `(ahora: ${modoEfectivo === 'dia' ? 'día' : 'noche'})`}</legend>
            <div className="flex gap-1.5">
              {MODOS.map(([valor, etiqueta, Icono]) => (
                <Opcion key={valor} activa={modo === valor} onClick={() => actualizar({ modo: valor })}>
                  <Icono className="mx-auto mb-0.5 h-4 w-4" aria-hidden="true" />{etiqueta}
                </Opcion>
              ))}
            </div>
          </fieldset>
          <fieldset>
            <legend className="mb-1.5 text-xs font-semibold uppercase text-slate-500">Tamaño del texto: {Math.round(escala * 100)}%</legend>
            <div className="flex items-center gap-2">
              <button type="button" onClick={() => cambiarEscala(-0.1)} disabled={escala <= ESCALA_MINIMA} aria-label="Reducir texto"
                className="rounded-lg border border-slate-300 p-1.5 disabled:opacity-40"><Minus className="h-4 w-4" aria-hidden="true" /></button>
              <input type="range" min={ESCALA_MINIMA} max={ESCALA_MAXIMA} step="0.1" value={escala} aria-label="Tamaño del texto"
                onChange={(e) => actualizar({ escala: Number(e.target.value) })} className="flex-1 accent-blue-800" />
              <button type="button" onClick={() => cambiarEscala(0.1)} disabled={escala >= ESCALA_MAXIMA} aria-label="Aumentar texto"
                className="rounded-lg border border-slate-300 p-1.5 disabled:opacity-40"><Plus className="h-4 w-4" aria-hidden="true" /></button>
            </div>
          </fieldset>
          <label className="flex items-center justify-between gap-2 text-sm font-medium">
            <span className="flex items-center gap-2"><Contrast className="h-4 w-4" aria-hidden="true" />Alto contraste</span>
            <input type="checkbox" role="switch" checked={altoContraste} onChange={(e) => actualizar({ altoContraste: e.target.checked })} className="h-4 w-4 accent-blue-800" />
          </label>
          <button type="button" onClick={restablecer} className="inline-flex items-center gap-1 text-xs text-blue-800 hover:underline">
            <RotateCcw className="h-3.5 w-3.5" aria-hidden="true" />Restablecer valores
          </button>
        </div>
      )}
    </div>
  );
}
