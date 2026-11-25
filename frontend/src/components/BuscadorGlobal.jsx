import { useEffect, useId, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { FileText, Link2, QrCode, Search } from 'lucide-react';
import { buscarGlobal } from '../api/estadisticasApi';
import { useAuth } from '../context/AuthContext';

const ESPERA_MS = 300;

/** Opciones del menú (cualquier nivel) cuyo título contiene el texto. */
const opcionesDeMenu = (menus, texto) => menus.flatMap((opcion) => [
  ...(opcion.ruta && opcion.titulo.toLowerCase().includes(texto) ? [opcion] : []),
  ...opcionesDeMenu(opcion.hijos ?? [], texto),
]);

/**
 * Búsqueda global predictiva del encabezado: trámites (código, estudiante, C.I., registro),
 * certificados (número) y opciones del menú. Navegable con teclado (flechas, Enter, Escape).
 */
export default function BuscadorGlobal() {
  const { user, menus } = useAuth();
  const navigate = useNavigate();
  const idLista = useId();
  const contenedor = useRef(null);
  const [texto, setTexto] = useState('');
  const [resultados, setResultados] = useState({ tramites: [], certificados: [] });
  const [abierto, setAbierto] = useState(false);
  const [activo, setActivo] = useState(-1);
  const [buscando, setBuscando] = useState(false);
  const esEstudiante = user?.rol === 'ESTUDIANTE';
  const consulta = texto.trim();

  useEffect(() => {
    if (consulta.length < 2) return undefined;
    const controlador = new AbortController();
    const temporizador = setTimeout(() => {
      setBuscando(true);
      buscarGlobal(consulta, { signal: controlador.signal })
        .then((res) => { setResultados(res.data); setActivo(-1); })
        .catch(() => {})
        .finally(() => setBuscando(false));
    }, ESPERA_MS);
    return () => { clearTimeout(temporizador); controlador.abort(); };
  }, [consulta]);

  useEffect(() => {
    const cerrar = (event) => { if (!contenedor.current?.contains(event.target)) setAbierto(false); };
    document.addEventListener('mousedown', cerrar);
    return () => document.removeEventListener('mousedown', cerrar);
  }, []);

  const corto = consulta.length < 2;
  const items = corto ? [] : [
    ...resultados.tramites.map((t) => ({
      clave: `t${t.id}`, icono: FileText, titulo: `${t.codigo} · ${t.estudiante}`, detalle: `${t.tipo} · ${t.estado.replaceAll('_', ' ')}`,
      ruta: `${esEstudiante ? '/mis-tramites' : '/tramites'}/${t.id}`,
    })),
    ...resultados.certificados.map((c) => ({
      clave: `c${c.id}`, icono: QrCode, titulo: `Certificado ${c.numero}`, detalle: `${c.tramite_codigo} · ${c.estado}`, ruta: `/tramites/${c.tramite_id}`,
    })),
    ...opcionesDeMenu(menus, consulta.toLowerCase()).slice(0, 4).map((m) => ({
      clave: `m${m.id}`, icono: Link2, titulo: m.titulo, detalle: 'Ir a la sección', ruta: m.ruta,
    })),
  ];

  const ir = (item) => {
    setAbierto(false);
    setTexto('');
    navigate(item.ruta);
  };

  const teclado = (event) => {
    if (event.key === 'Escape') { setAbierto(false); return; }
    if (!items.length) return;
    if (event.key === 'ArrowDown') { event.preventDefault(); setAbierto(true); setActivo((i) => (i + 1) % items.length); }
    if (event.key === 'ArrowUp') { event.preventDefault(); setActivo((i) => (i <= 0 ? items.length - 1 : i - 1)); }
    if (event.key === 'Enter' && activo >= 0) { event.preventDefault(); ir(items[activo]); }
  };

  return (
    <div ref={contenedor} className="relative w-full max-w-xl">
      <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" aria-hidden="true" />
      <input type="search" role="combobox" aria-expanded={abierto && !corto} aria-controls={idLista} aria-autocomplete="list"
        aria-activedescendant={activo >= 0 ? `${idLista}-${activo}` : undefined} aria-label="Búsqueda global"
        placeholder={esEstudiante ? 'Buscar en mis trámites o secciones...' : 'Buscar trámite, C.I., registro, certificado o sección...'}
        value={texto} maxLength={60}
        onChange={(e) => { setTexto(e.target.value); setAbierto(true); }}
        onFocus={() => setAbierto(true)} onKeyDown={teclado}
        className="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm" />
      {abierto && !corto && (
        <ul id={idLista} role="listbox" className="absolute left-0 right-0 z-50 mt-1 max-h-96 overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
          {items.map((item, indice) => {
            const Icono = item.icono;
            return (
              <li key={item.clave} id={`${idLista}-${indice}`} role="option" aria-selected={activo === indice}>
                <button type="button" onMouseEnter={() => setActivo(indice)} onClick={() => ir(item)}
                  className={`flex w-full items-start gap-2 px-3 py-2 text-left text-sm ${activo === indice ? 'bg-blue-50' : ''}`}>
                  <Icono className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                  <span><span className="block font-medium text-slate-800">{item.titulo}</span><span className="block text-xs text-slate-500">{item.detalle}</span></span>
                </button>
              </li>
            );
          })}
          {!items.length && <li className="px-3 py-3 text-sm text-slate-500">{buscando ? 'Buscando...' : 'Sin resultados.'}</li>}
        </ul>
      )}
    </div>
  );
}
