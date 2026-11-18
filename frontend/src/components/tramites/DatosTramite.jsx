export default function DatosTramite({ form, setForm, tipo, setTipo, carreras, carreraRequerida }) {
  const actualizarTipo = (nuevoTipo) => {
    setTipo(nuevoTipo);
    if (nuevoTipo === 'DESCUENTO_DIPLOMADO') setForm((prev) => ({ ...prev, destino: 'NACIONAL', pais_destino: '' }));
  };
  return (
    <section className="bg-white p-5 rounded-xl border border-slate-200 space-y-4">
      <h2 className="font-bold text-slate-800">Datos del trámite</h2>
      <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="tipo-tramite">Tipo *</label>
          <select id="tipo-tramite" required value={tipo} onChange={(e) => actualizarTipo(e.target.value)} className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
            <option value="LEGALIZACION">Legalización</option><option value="DESCUENTO_DIPLOMADO">Descuento Diplomado</option>
          </select>
        </div>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="destino">Destino *</label>
          <select id="destino" required disabled={tipo === 'DESCUENTO_DIPLOMADO'} value={form.destino}
            onChange={(e) => setForm({ ...form, destino: e.target.value, pais_destino: e.target.value === 'NACIONAL' ? '' : form.pais_destino })}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white disabled:bg-slate-100">
            <option value="NACIONAL">Nacional</option><option value="EXTERIOR">Exterior</option>
          </select>
        </div>
        {form.destino === 'EXTERIOR' && <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="pais-destino">País de destino *</label>
          <input id="pais-destino" required maxLength={80} value={form.pais_destino} onChange={(e) => setForm({ ...form, pais_destino: e.target.value })}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
        </div>}
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="institucion-destino">Institución de destino</label>
          <input id="institucion-destino" maxLength={200} value={form.institucion_destino} onChange={(e) => setForm({ ...form, institucion_destino: e.target.value })}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
        </div>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="prioridad">Prioridad</label>
          <select id="prioridad" value={form.prioridad} onChange={(e) => setForm({ ...form, prioridad: e.target.value })}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
            <option value="NORMAL">Normal</option><option value="ALTA">Alta</option><option value="URGENTE">Urgente</option>
          </select>
        </div>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="carrera-id">Carrera del solicitante {carreraRequerida ? '*' : ''}</label>
          <select id="carrera-id" required={carreraRequerida} value={form.carrera_id} onChange={(e) => setForm({ ...form, carrera_id: e.target.value })}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white">
            <option value="">Usar carrera registrada</option>
            {carreras.map((carrera) => <option key={carrera.id} value={carrera.id}>{carrera.nombre} — {carrera.facultad}</option>)}
          </select>
        </div>
      </div>
      <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="finalidad">Finalidad</label>
        <textarea id="finalidad" maxLength={255} rows="2" value={form.finalidad} onChange={(e) => setForm({ ...form, finalidad: e.target.value })}
          className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
      </div>
    </section>
  );
}
