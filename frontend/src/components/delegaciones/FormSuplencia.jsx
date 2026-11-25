import { FileSignature, PlusCircle } from 'lucide-react';

const MOTIVOS = [['VIAJE', 'Viaje'], ['COMISION_OFICIAL', 'Comisión oficial'], ['AUSENCIA', 'Ausencia'], ['VACACION', 'Vacación'], ['OTRO', 'Otro']];
const campo = 'w-full border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white';

export default function FormSuplencia({ datos, form, setForm, onSubmit, enviando }) {
  const estatutario = datos.suplentes.find((s) => s.id === datos.suplente_estatutario_id);
  const saltaPrelacion = form.suplente_id && String(form.suplente_id) !== String(datos.suplente_estatutario_id);
  const actualizar = (clave, valor) => setForm({ ...form, [clave]: valor });

  return (
    <form onSubmit={onSubmit} noValidate className="bg-white p-5 rounded-xl border border-slate-200 space-y-3 text-sm">
      <h2 className="font-bold text-slate-800 flex items-center gap-2"><FileSignature className="w-4 h-4 text-blue-800" aria-hidden="true" />Registrar suplencia del Vicerrectorado</h2>
      {estatutario && (
        <p className="rounded-lg bg-blue-50 p-2 text-xs text-blue-900">
          Por orden de prelación corresponde: <span className="font-medium">{estatutario.nombres} {estatutario.apellidos}</span> ({estatutario.facultad}).
        </p>
      )}
      <div className="grid grid-cols-2 gap-3">
        <label className="block font-medium text-slate-700">Titular (Vicerrector/a) *
          <select value={form.titular_id} onChange={(e) => actualizar('titular_id', e.target.value)} className={`mt-1 ${campo}`}>
            <option value="">Seleccione...</option>
            {datos.vicerrectores.map((v) => <option key={v.id} value={v.id}>{v.nombres} {v.apellidos}</option>)}
          </select>
        </label>
        <label className="block font-medium text-slate-700">Suplente (Decano/a) *
          <select value={form.suplente_id} onChange={(e) => actualizar('suplente_id', e.target.value)} className={`mt-1 ${campo}`}>
            <option value="">Seleccione...</option>
            {datos.suplentes.map((s) => <option key={s.id} value={s.id}>#{s.orden_prelacion} {s.nombres} {s.apellidos} ({s.facultad})</option>)}
          </select>
        </label>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <label className="block font-medium text-slate-700">N° de Resolución Rectoral *
          <input maxLength={50} placeholder="RR-145/2026" value={form.nro_resolucion} onChange={(e) => actualizar('nro_resolucion', e.target.value)} className={`mt-1 ${campo}`} />
        </label>
        <label className="block font-medium text-slate-700">Fecha de la resolución *
          <input type="date" value={form.fecha_resolucion} onChange={(e) => actualizar('fecha_resolucion', e.target.value)} className={`mt-1 ${campo}`} />
        </label>
      </div>
      <label className="block font-medium text-slate-700">Motivo *
        <select value={form.motivo} onChange={(e) => actualizar('motivo', e.target.value)} className={`mt-1 ${campo}`}>
          <option value="">Seleccione...</option>
          {MOTIVOS.map(([valor, etiqueta]) => <option key={valor} value={valor}>{etiqueta}</option>)}
        </select>
      </label>
      <label className="block font-medium text-slate-700">Detalle del motivo {saltaPrelacion || form.motivo === 'OTRO' ? '*' : '(opcional)'}
        <textarea rows="2" maxLength={255} value={form.detalle_motivo} onChange={(e) => actualizar('detalle_motivo', e.target.value)}
          placeholder={saltaPrelacion ? 'Justifique por qué no suple el Decano que corresponde por prelación' : ''} className={`mt-1 ${campo}`} />
      </label>
      <div className="grid grid-cols-2 gap-3">
        <label className="block font-medium text-slate-700">Inicio *
          <input type="date" min={form.fecha_resolucion || undefined} value={form.fecha_inicio} onChange={(e) => actualizar('fecha_inicio', e.target.value)} className={`mt-1 ${campo}`} />
        </label>
        <label className="block font-medium text-slate-700">Fin *
          <input type="date" min={form.fecha_inicio || undefined} value={form.fecha_fin} onChange={(e) => actualizar('fecha_fin', e.target.value)} className={`mt-1 ${campo}`} />
        </label>
      </div>
      <button type="submit" disabled={enviando} className="flex items-center gap-2 bg-blue-900 hover:bg-blue-800 text-white px-4 py-2 rounded-lg font-medium disabled:opacity-50">
        <PlusCircle className="w-4 h-4" aria-hidden="true" />Activar suplencia
      </button>
    </form>
  );
}
