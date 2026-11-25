import { createContext, useContext, useEffect, useState } from 'react';

const CLAVE = 'preferencias-visuales';
const INICIALES = { tema: 'adultos', modo: 'auto', escala: 1, altoContraste: false };
export const ESCALA_MINIMA = 0.9;
export const ESCALA_MAXIMA = 1.5;

/** Modo automático: día de 07:00 a 18:59, noche el resto. */
const modoPorHorario = (fecha = new Date()) => (fecha.getHours() >= 7 && fecha.getHours() < 19 ? 'dia' : 'noche');

const leer = () => {
  try {
    return { ...INICIALES, ...JSON.parse(localStorage.getItem(CLAVE) || '{}') };
  } catch {
    return INICIALES;
  }
};

const PreferenciasContext = createContext();

/**
 * Tema por segmento (niños, jóvenes, adultos), modo día/noche (manual o automático por horario),
 * tamaño de texto y alto contraste. Se guardan en el navegador y se aplican como atributos de <html>.
 */
export function PreferenciasProvider({ children }) {
  const [preferencias, setPreferencias] = useState(leer);
  const [modoHorario, setModoHorario] = useState(() => modoPorHorario());
  const modoEfectivo = preferencias.modo === 'auto' ? modoHorario : preferencias.modo;

  useEffect(() => {
    if (preferencias.modo !== 'auto') return undefined;
    const intervalo = setInterval(() => setModoHorario(modoPorHorario()), 60_000);
    return () => clearInterval(intervalo);
  }, [preferencias.modo]);

  useEffect(() => {
    const raiz = document.documentElement;
    raiz.dataset.tema = preferencias.tema;
    raiz.dataset.modo = modoEfectivo;
    raiz.dataset.contraste = preferencias.altoContraste ? 'alto' : 'normal';
    raiz.style.setProperty('--escala-fuente', String(preferencias.escala));
    localStorage.setItem(CLAVE, JSON.stringify(preferencias));
  }, [preferencias, modoEfectivo]);

  const actualizar = (cambios) => setPreferencias((previas) => ({ ...previas, ...cambios }));
  const cambiarEscala = (delta) => setPreferencias((previas) => ({
    ...previas,
    escala: Math.min(ESCALA_MAXIMA, Math.max(ESCALA_MINIMA, Math.round((previas.escala + delta) * 10) / 10)),
  }));

  return (
    <PreferenciasContext.Provider value={{ ...preferencias, modoEfectivo, actualizar, cambiarEscala, restablecer: () => setPreferencias(INICIALES) }}>
      {children}
    </PreferenciasContext.Provider>
  );
}

export const usePreferencias = () => useContext(PreferenciasContext);
