import { useState } from 'react';
import { Trash2 } from 'lucide-react';
import { moneda } from './calculosTramite';

const CONCEPTOS = [
  ['LEGALIZACION', 'Legalización'],
  ['EMISION_ORIGEN', 'Emisión en origen'],
  ['AVANCE_ACADEMICO', 'Avance Académico'],
];
const VACIO = { codigo_recibo: '', concepto: 'LEGALIZACION', importe: '', fecha_pago: '' };
const hoy = () => new Date().toISOString().slice(0, 10);

/**
 * Recibos emitidos por Caja/Tesorería. El sistema no cobra: Ventanilla registra el recibo y
 * PostgreSQL contrasta importe_pagado (recibos de LEGALIZACION) con importe_esperado.
 */
export default function ComprobantesCaja({ tramite, editable, disabled, onRegistrar, onEliminar }) {
  const [form, setForm] = useState(VACIO);
  const [error, setError] = useState('');
  const faltante = Math.max(0, Number(tramite.importe_esperado) - Number(tramite.importe_pagado));

  const registrar = async (event) => {
    event.preventDefault();
    if (!form.codigo_recibo.trim()) { setError('Ingrese el número de recibo.'); return; }
    if (!(Number(form.importe) > 0)) { setError('El importe debe ser mayor a cero.'); return; }
    if (!form.fecha_pago || form.fecha_pago > hoy()) { setError('Indique una fecha de pago válida (no posterior a hoy).'); return; }
    setError('');
    if (await onRegistrar({ ...form, codigo_recibo: form.codigo_recibo.trim(), importe: Number(form.importe) })) setForm(VACIO);
  };

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-5">
      <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-lg font-semibold text-slate-800">Recibos de Caja / Tesorería</h2>
        <p className="text-sm text-slate-700">
          Esperado <span className="font-semibold">{moneda(tramite.importe_esperado)}</span> · Pagado <span className="font-semibold">{moneda(tramite.importe_pagado)}</span>
          {faltante > 0 && tramite.tipo === 'LEGALIZACION' && <span className="ml-2 font-medium text-amber-700">Falta {moneda(faltante)}</span>}
        </p>
      </div>
      <ul className="space-y-2">
        {(tramite.comprobantes || []).map((recibo) => (
          <li key={recibo.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-100 p-3 text-sm">
            <span><span className="font-medium text-slate-800">N° {recibo.codigo_recibo}</span> · {recibo.concepto.replaceAll('_', ' ')} · {moneda(recibo.importe)} · {recibo.fecha_pago}</span>
            {editable && (
              <button type="button" disabled={disabled} onClick={() => onEliminar(recibo.id)} aria-label={`Eliminar recibo ${recibo.codigo_recibo}`}
                className="rounded p-1 text-red-600 hover:bg-red-50"><Trash2 className="h-4 w-4" aria-hidden="true" /></button>
            )}
          </li>
        ))}
        {!tramite.comprobantes?.length && <li className="text-sm text-slate-500">No hay recibos registrados.</li>}
      </ul>
      {editable && (
        <form onSubmit={registrar} noValidate className="mt-4 grid gap-3 sm:grid-cols-5 sm:items-end">
          <label className="text-xs font-medium text-slate-700">N° de recibo *
            <input maxLength={30} value={form.codigo_recibo} onChange={(e) => setForm({ ...form, codigo_recibo: e.target.value })} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label className="text-xs font-medium text-slate-700">Concepto *
            <select value={form.concepto} onChange={(e) => setForm({ ...form, concepto: e.target.value })} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
              {CONCEPTOS.map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
            </select>
          </label>
          <label className="text-xs font-medium text-slate-700">Importe (Bs) *
            <input type="number" min="0.01" step="0.01" value={form.importe} placeholder={faltante ? faltante.toFixed(2) : ''}
              onChange={(e) => setForm({ ...form, importe: e.target.value })} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <label className="text-xs font-medium text-slate-700">Fecha de pago *
            <input type="date" max={hoy()} value={form.fecha_pago} onChange={(e) => setForm({ ...form, fecha_pago: e.target.value })} className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          </label>
          <button type="submit" disabled={disabled} className="rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50">Registrar recibo</button>
          {error && <p role="alert" className="text-xs text-red-700 sm:col-span-5">{error}</p>}
        </form>
      )}
    </section>
  );
}
