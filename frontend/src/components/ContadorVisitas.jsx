import { useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { Eye } from 'lucide-react';
import { registrarVisita } from '../api/authApi';

/** Agrupa las rutas con identificadores (/tramites/15) en una sola página (/tramites/:id). */
const normalizarRuta = (ruta) => ruta
  .split('/')
  .map((segmento) => (/^\d+$/.test(segmento) || /^[0-9a-f-]{36}$/i.test(segmento) ? ':id' : segmento))
  .join('/') || '/';

/** Evita contar dos veces la misma entrada (StrictMode ejecuta los efectos dos veces en desarrollo). */
let ultimoRegistro = { ruta: null, momento: 0, peticion: null };
const registrarUnaVez = (ruta, titulo) => {
  const ahora = Date.now();
  if (ultimoRegistro.ruta !== ruta || ahora - ultimoRegistro.momento > 1000) {
    ultimoRegistro = { ruta, momento: ahora, peticion: registrarVisita(ruta, titulo) };
  }
  return ultimoRegistro.peticion;
};

/**
 * Contador de visitas persistente (tabla contador_visita): suma una visita al entrar a cada página
 * y muestra el total. Va en el pie de página de todas las vistas, públicas y privadas.
 */
export default function ContadorVisitas({ titulo, className = '' }) {
  const { pathname } = useLocation();
  const [visitas, setVisitas] = useState(null);
  const tituloActual = useRef(titulo);

  useEffect(() => {
    tituloActual.current = titulo;
  }, [titulo]);

  useEffect(() => {
    let vigente = true;
    registrarUnaVez(normalizarRuta(pathname), tituloActual.current || document.title || pathname)
      .then((res) => { if (vigente) setVisitas(res.data.visitas); })
      .catch(() => {});
    return () => { vigente = false; };
  }, [pathname]);

  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 font-medium text-slate-700 ${className}`}>
      <Eye className="h-3.5 w-3.5" aria-hidden="true" />
      Visitas a esta página: {visitas ?? '…'}
    </span>
  );
}
