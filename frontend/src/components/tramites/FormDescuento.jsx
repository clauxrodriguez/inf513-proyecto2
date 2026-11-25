const campo = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm';
const hoy = () => new Date().toISOString().slice(0, 10);

/** Descuento del 50% (Res. Vicerrectoral 01-28/2013): el plazo de 1 año desde la última nota lo calcula la base de datos. */
export default function FormDescuento({ form, setForm, archivo, setArchivo }) {
  const actualizar = (campoForm, valor) => setForm({ ...form, [campoForm]: valor });

  return (
    <section className="bg-white p-5 rounded-xl border border-slate-200 space-y-4">
      <div>
        <h2 className="font-bold text-slate-800">Solicitud de descuento de diplomado</h2>
        <p className="text-xs text-slate-500 mt-1">Corresponde el 50% si la solicitud se presenta hasta un año después de su última nota.</p>
      </div>
      <div className="grid sm:grid-cols-2 gap-3">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="unidad-postgrado">Unidad de Postgrado *</label>
          <input id="unidad-postgrado" required maxLength={150} value={form.unidad_postgrado} onChange={(e) => actualizar('unidad_postgrado', e.target.value)} className={campo} />
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="nombre-diplomado">Nombre del diplomado *</label>
          <input id="nombre-diplomado" required maxLength={200} value={form.nombre_diplomado} onChange={(e) => actualizar('nombre_diplomado', e.target.value)} className={campo} />
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="avance-academico">N° de Avance Académico *</label>
          <input id="avance-academico" required maxLength={20} value={form.nro_avance_academico} onChange={(e) => actualizar('nro_avance_academico', e.target.value)} className={campo} />
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="fecha-ultima-nota">Fecha de su última nota *</label>
          <input id="fecha-ultima-nota" type="date" required max={hoy()} value={form.fecha_ultima_nota} onChange={(e) => actualizar('fecha_ultima_nota', e.target.value)} className={campo} />
        </div>
      </div>
      <div>
        <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="respaldo-descuento">Avance Académico en PDF (recomendado)</label>
        <input id="respaldo-descuento" type="file" accept="application/pdf,.pdf" onChange={(e) => setArchivo(e.target.files?.[0] || null)}
          className="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs" />
        {archivo && <p className="text-xs text-slate-500 mt-1">{archivo.name}</p>}
      </div>
    </section>
  );
}
