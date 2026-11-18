import { FileSignature, PlusCircle } from 'lucide-react';

const MOTIVOS = [
  ['VIAJE', 'Viaje'],
  ['COMISION_OFICIAL', 'Comisión oficial'],
  ['AUSENCIA', 'Ausencia'],
  ['VACACION', 'Vacación'],
  ['LICENCIA', 'Licencia'],
  ['BAJA_MEDICA', 'Baja médica'],
  ['ASUME_RECTORADO', 'Asume Rectorado'],
  ['OTRO', 'Otro'],
];

export default function FormSuplencia({ datos, form, setForm, setArchivoPdf, onSubmit }) {
  const primerSuplente = datos.suplentes[0];
  const seleccionSaltaPrelacion = form.suplente_id && String(form.suplente_id) !== String(primerSuplente?.id);
  const actualizar = (campo, valor) => setForm({ ...form, [campo]: valor });

  return (
    <form onSubmit={onSubmit} className="bg-white p-5 rounded-xl border border-slate-200 space-y-3 text-sm">
      <h2 className="font-bold text-slate-800 flex items-center gap-2"><FileSignature className="w-4 h-4 text-blue-800" />Registrar Suplencia de Vicerrectorado</h2>
      <div className="grid grid-cols-2 gap-3">
        <div><label className="block font-medium text-slate-700 mb-1">Titular (Vicerrector/a) *</label>
          <select required value={form.titular_id} onChange={(e) => actualizar('titular_id', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white">
            <option value="">Seleccione Vicerrector/a...</option>
            {datos.vicerrectores.map((v) => <option key={v.id} value={v.id}>{v.nombres} {v.apellidos}</option>)}
          </select>
        </div>
        <div><label className="block font-medium text-slate-700 mb-1">Suplente (Por Prelación) *</label>
          <select required value={form.suplente_id} onChange={(e) => actualizar('suplente_id', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white">
            <option value="">Seleccione Decano...</option>
            {datos.suplentes.map((s) => <option key={s.id} value={s.id}>#{s.orden_prelacion || '-'} {s.nombres} {s.apellidos} ({s.facultad || 'Sin facultad'})</option>)}
          </select>
        </div>
      </div>
      <div className="grid grid-cols-3 gap-2">
        <div><label className="block font-medium text-slate-700 mb-1">Tipo de resolución *</label>
          <select required value={form.tipo_resolucion} onChange={(e) => actualizar('tipo_resolucion', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2 py-1.5 bg-white">
            <option value="RECTORAL">Resolución Rectoral</option><option value="ICU">Resolución ICU</option>
          </select>
        </div>
        <div><label className="block font-medium text-slate-700 mb-1">N° Resolución *</label>
          <input required maxLength={50} placeholder="RR-145/2026" value={form.nro_resolucion} onChange={(e) => actualizar('nro_resolucion', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5" />
        </div>
        <div><label className="block font-medium text-slate-700 mb-1">Fecha de resolución *</label>
          <input type="date" required value={form.fecha_resolucion} onChange={(e) => actualizar('fecha_resolucion', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2 py-1.5" />
        </div>
      </div>
      <div>
        <label className="block font-medium text-slate-700 mb-1">Motivo *</label>
        <select required value={form.motivo} onChange={(e) => actualizar('motivo', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white">
          <option value="">Seleccione motivo...</option>
          {MOTIVOS.map(([valor, etiqueta]) => <option key={valor} value={valor}>{etiqueta}</option>)}
        </select>
      </div>
      <div>
        <label className="block font-medium text-slate-700 mb-1">Detalle del motivo</label>
        <input maxLength={255} value={form.detalle_motivo} onChange={(e) => actualizar('detalle_motivo', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5" />
      </div>
      <div>
        <label className="block font-medium text-slate-700 mb-1">Justificación de prelación {seleccionSaltaPrelacion ? '*' : '(Opcional)'}</label>
        <input maxLength={255} required={Boolean(seleccionSaltaPrelacion)} value={form.justificacion_prelacion}
          onChange={(e) => actualizar('justificacion_prelacion', e.target.value)}
          placeholder={seleccionSaltaPrelacion ? 'Explique por qué se omite al Decano de mayor prelación' : ''}
          className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5" />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div><label className="block font-medium text-slate-700 mb-1">Inicio de vigencia *</label>
          <input type="date" required min={form.fecha_resolucion || undefined} value={form.fecha_inicio} onChange={(e) => actualizar('fecha_inicio', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5" />
        </div>
        <div><label className="block font-medium text-slate-700 mb-1">Fin de vigencia *</label>
          <input type="date" required min={form.fecha_inicio || undefined} value={form.fecha_fin} onChange={(e) => actualizar('fecha_fin', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5" />
        </div>
      </div>
      <div><label className="block font-medium text-slate-700 mb-1">Resolución firmada (PDF obligatorio) *</label>
        <input type="file" accept="application/pdf" required onChange={(e) => setArchivoPdf(e.target.files?.[0] || null)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs" />
      </div>
      <button type="submit" className="flex items-center gap-2 bg-blue-900 hover:bg-blue-800 text-white px-4 py-2 rounded-lg font-medium"><PlusCircle className="w-4 h-4" />Activar Suplencia</button>
    </form>
  );
}
