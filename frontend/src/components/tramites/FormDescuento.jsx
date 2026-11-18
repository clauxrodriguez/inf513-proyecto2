export default function FormDescuento({ form, setForm, archivo, setArchivo }) {
  const actualizar = (campo, valor) => setForm({ ...form, [campo]: valor });
  return (
    <section className="bg-white p-5 rounded-xl border border-slate-200 space-y-4">
      <h2 className="font-bold text-slate-800">Solicitud de descuento de diplomado</h2>
      <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="unidad-postgrado">Unidad de Postgrado *</label>
          <input id="unidad-postgrado" required maxLength={150} value={form.unidad_postgrado} onChange={(e) => actualizar('unidad_postgrado', e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" /></div>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="nombre-diplomado">Nombre del diplomado *</label>
          <input id="nombre-diplomado" required maxLength={200} value={form.nombre_diplomado} onChange={(e) => actualizar('nombre_diplomado', e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" /></div>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="gestion-diplomado">Gestión (ej. I-2026) *</label>
          <input id="gestion-diplomado" required maxLength={10} value={form.gestion_diplomado} onChange={(e) => actualizar('gestion_diplomado', e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" /></div>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="fecha-inicio-diplomado">Inicio del diplomado</label>
          <input id="fecha-inicio-diplomado" type="date" value={form.fecha_inicio_diplomado} onChange={(e) => actualizar('fecha_inicio_diplomado', e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" /></div>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="avance-academico">N° de avance académico *</label>
          <input id="avance-academico" required maxLength={20} value={form.nro_avance_academico} onChange={(e) => actualizar('nro_avance_academico', e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" /></div>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="regimen">Régimen *</label>
          <select id="regimen" required value={form.regimen} onChange={(e) => actualizar('regimen', e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
            <option value="SEMESTRAL">Semestral</option><option value="ANUAL">Anual</option>
          </select></div>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="anio-ultima-nota">Año de última nota *</label>
          <input id="anio-ultima-nota" type="number" min="1950" max="2100" required value={form.anio_ultima_nota} onChange={(e) => actualizar('anio_ultima_nota', e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" /></div>
        {form.regimen === 'SEMESTRAL' && <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="periodo-ultima-nota">Periodo de última nota *</label>
          <select id="periodo-ultima-nota" required value={form.periodo_ultima_nota} onChange={(e) => actualizar('periodo_ultima_nota', e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
            <option value="">Seleccione...</option><option value="1">1</option><option value="2">2</option>
          </select></div>}
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="modulo-reprobado">Módulo reprobado (opcional)</label>
          <input id="modulo-reprobado" maxLength={150} value={form.modulo_reprobado} onChange={(e) => actualizar('modulo_reprobado', e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" /></div>
      </div>
      <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="respaldo-descuento">PDF de respaldo *</label>
        <input id="respaldo-descuento" type="file" required accept="application/pdf,.pdf" onChange={(e) => setArchivo(e.target.files?.[0] || null)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs" />
        {archivo && <p className="text-xs text-slate-500 mt-1">{archivo.name}</p>}
      </div>
    </section>
  );
}
