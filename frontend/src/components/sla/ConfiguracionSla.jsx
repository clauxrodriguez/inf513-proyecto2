import { useState } from 'react';
import { Settings, Trash2 } from 'lucide-react';
import { mensajeError } from '../../api/despachoApi';
import { eliminarConfiguracionSla, guardarConfiguracionSla } from '../../api/slaApi';
import Alerta from '../ui/Alerta';

const OPCIONES = [['', 'General (todos los trámites)'], ['LEGALIZACION', 'Legalización'], ['DESCUENTO_DIPLOMADO', 'Descuento de diplomado']];

/** SF-3: el Administrador ajusta el plazo y el umbral preventivo, general o por tipo de trámite. */
export default function ConfiguracionSla({ configuracion, esAdministrador, onActualizado }) {
  const [form, setForm] = useState({ tipo: '', plazo_horas: '72', porcentaje_alerta: '75' });
  const [error, setError] = useState('');
  const [guardando, setGuardando] = useState(false);

  const elegirTipo = (tipo) => {
    const actual = configuracion.find((c) => (c.tipo || '') === tipo) || configuracion.find((c) => c.tipo === null);
    setForm({ tipo, plazo_horas: String(actual?.plazo_horas ?? 72), porcentaje_alerta: String(actual?.porcentaje_alerta ?? 75) });
  };

  const ejecutar = async (accion) => {
    setError('');
    setGuardando(true);
    try {
      onActualizado((await accion()).data.message);
    } catch (err) {
      setError(mensajeError(err, 'No se pudo guardar la configuración SLA.'));
    } finally {
      setGuardando(false);
    }
  };

  const guardar = (event) => {
    event.preventDefault();
    ejecutar(() => guardarConfiguracionSla({
      tipo: form.tipo || null,
      plazo_horas: Number(form.plazo_horas),
      porcentaje_alerta: Number(form.porcentaje_alerta),
    }));
  };
  const umbral = (Number(form.plazo_horas) * Number(form.porcentaje_alerta)) / 100;

  return (
    <section className="space-y-4 rounded-xl border border-slate-200 bg-white p-5 text-sm">
      <h2 className="flex items-center gap-2 font-semibold text-slate-800"><Settings className="h-4 w-4" />Parámetros SLA vigentes</h2>
      <ul className="space-y-1.5">
        {configuracion.map((c) => (
          <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2">
            <span>
              <span className="font-medium text-slate-800">{c.tipo ? c.tipo.replaceAll('_', ' ') : 'General'}</span>
              <span className="text-slate-600"> · plazo {c.plazo_horas} h hábiles · alerta al {c.porcentaje_alerta}% ({c.umbral_alerta_horas} h)</span>
            </span>
            {esAdministrador && c.tipo && (
              <button type="button" disabled={guardando} onClick={() => ejecutar(() => eliminarConfiguracionSla(c.tipo))}
                className="inline-flex items-center gap-1 text-xs text-red-600 hover:underline disabled:opacity-50">
                <Trash2 className="h-3.5 w-3.5" />Usar la general
              </button>
            )}
          </li>
        ))}
      </ul>

      {esAdministrador && (
        <form onSubmit={guardar} className="grid gap-3 border-t border-slate-200 pt-4 sm:grid-cols-4 sm:items-end">
          <label>
            <span className="mb-1 block font-medium text-slate-700">Aplica a</span>
            <select value={form.tipo} onChange={(e) => elegirTipo(e.target.value)} className="w-full rounded-lg border border-slate-300 bg-white px-2.5 py-1.5">
              {OPCIONES.map(([valor, etiqueta]) => <option key={valor} value={valor}>{etiqueta}</option>)}
            </select>
          </label>
          <label>
            <span className="mb-1 block font-medium text-slate-700">Plazo (horas hábiles)</span>
            <input type="number" required min="1" max="9999" step="0.5" value={form.plazo_horas} onChange={(e) => setForm({ ...form, plazo_horas: e.target.value })} className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5" />
          </label>
          <label>
            <span className="mb-1 block font-medium text-slate-700">Alerta preventiva (%)</span>
            <input type="number" required min="1" max="99" step="1" value={form.porcentaje_alerta} onChange={(e) => setForm({ ...form, porcentaje_alerta: e.target.value })} className="w-full rounded-lg border border-slate-300 px-2.5 py-1.5" />
          </label>
          <button type="submit" disabled={guardando} className="rounded-lg bg-blue-900 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-50">
            {guardando ? 'Guardando...' : 'Guardar parámetros'}
          </button>
          <p className="text-xs text-slate-500 sm:col-span-4">La alerta preventiva se emitirá a las {Number.isFinite(umbral) ? umbral.toFixed(1) : '—'} h hábiles; el semáforo de todos los trámites se recalcula de inmediato.</p>
        </form>
      )}
      <Alerta mensaje={error} />
    </section>
  );
}
