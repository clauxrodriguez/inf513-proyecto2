import { Link, NavLink } from 'react-router-dom';
import { ShieldCheck } from 'lucide-react';
import ContadorVisitas from '../ContadorVisitas';
import PanelAccesibilidad from '../PanelAccesibilidad';

const enlaces = [
  ['/seguimiento', 'Seguimiento de trámite'],
  ['/validar-certificado', 'Validar certificado'],
];

/** Marco de las páginas públicas (sin sesión) del CU6. */
export default function LayoutPublico({ titulo, subtitulo, children }) {
  return (
    <div className="min-h-screen bg-slate-100">
      <header className="bg-blue-950 text-white">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-4">
          <Link to="/seguimiento" className="flex items-center gap-3">
            <span className="rounded-full bg-blue-800 p-2"><ShieldCheck className="h-5 w-5" /></span>
            <span>
              <span className="block text-xs uppercase tracking-wider text-blue-300">UAGRM · Vicerrectorado</span>
              <span className="block font-bold leading-tight">Sistema de Legalizaciones</span>
            </span>
          </Link>
          <nav className="flex flex-wrap gap-1 text-sm">
            {enlaces.map(([ruta, texto]) => (
              <NavLink key={ruta} to={ruta} className={({ isActive }) => `rounded-lg px-3 py-1.5 ${isActive ? 'bg-blue-800 text-white' : 'text-blue-200 hover:bg-blue-900 hover:text-white'}`}>
                {texto}
              </NavLink>
            ))}
            <Link to="/login" className="rounded-lg px-3 py-1.5 text-blue-200 hover:bg-blue-900 hover:text-white">Ingresar</Link>
            <PanelAccesibilidad claro />
          </nav>
        </div>
      </header>
      <main className="mx-auto max-w-5xl space-y-5 px-4 py-6">
        <div>
          <h1 className="text-xl font-bold text-slate-800">{titulo}</h1>
          {subtitulo && <p className="text-sm text-slate-500">{subtitulo}</p>}
        </div>
        {children}
      </main>
      <footer className="border-t border-slate-200 bg-white">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-2 px-4 py-3 text-xs text-slate-500">
          <span>Vicerrectorado UAGRM — Sistema de Legalizaciones con Firma Digital</span>
          <ContadorVisitas titulo={titulo} />
        </div>
      </footer>
    </div>
  );
}
