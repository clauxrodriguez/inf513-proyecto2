const campo = 'w-full border border-slate-300 rounded-lg px-3 py-2 text-sm bg-white disabled:bg-slate-100';

export default function DatosTramite({ form, setForm, tipo, setTipo, carreras }) {
  const esLegalizacion = tipo === 'LEGALIZACION';
  const actualizar = (cambios) => setForm({ ...form, ...cambios });

  return (
    <section className="bg-white p-5 rounded-xl border border-slate-200 space-y-4">
      <h2 className="font-bold text-slate-800">Datos del trámite</h2>
      <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="tipo-tramite">Tipo de trámite *</label>
          <select id="tipo-tramite" required value={tipo} onChange={(e) => setTipo(e.target.value)} className={campo}>
            <option value="LEGALIZACION">Legalización de documentos</option>
            <option value="DESCUENTO_DIPLOMADO">Descuento del 50% para diplomado</option>
          </select>
        </div>
        {carreras.length > 1 && (
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="carrera-id">Carrera *</label>
            <select id="carrera-id" required value={form.carrera_id} onChange={(e) => actualizar({ carrera_id: e.target.value })} className={campo}>
              <option value="">Seleccione su carrera...</option>
              {carreras.map((carrera) => <option key={carrera.id} value={carrera.id}>{carrera.nombre} — {carrera.facultad}</option>)}
            </select>
          </div>
        )}
        {esLegalizacion && (
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="destino">Destino *</label>
            <select id="destino" required value={form.destino}
              onChange={(e) => actualizar({ destino: e.target.value, pais_destino: e.target.value === 'NACIONAL' ? '' : form.pais_destino })} className={campo}>
              <option value="NACIONAL">Nacional</option>
              <option value="EXTERIOR">Exterior (requiere visado de Rectorado)</option>
            </select>
          </div>
        )}
        {esLegalizacion && form.destino === 'EXTERIOR' && (
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="pais-destino">País de destino *</label>
            <input id="pais-destino" required maxLength={80} value={form.pais_destino} onChange={(e) => actualizar({ pais_destino: e.target.value })} className={campo} />
          </div>
        )}
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="institucion-destino">Institución de destino</label>
          <input id="institucion-destino" maxLength={200} value={form.institucion_destino} onChange={(e) => actualizar({ institucion_destino: e.target.value })} className={campo} />
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="prioridad">Prioridad</label>
          <select id="prioridad" value={form.prioridad} onChange={(e) => actualizar({ prioridad: e.target.value })} className={campo}>
            <option value="NORMAL">Normal</option><option value="ALTA">Alta</option><option value="URGENTE">Urgente</option>
          </select>
        </div>
      </div>
      <div>
        <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="finalidad">Finalidad</label>
        <textarea id="finalidad" maxLength={255} rows="2" value={form.finalidad} onChange={(e) => actualizar({ finalidad: e.target.value })}
          className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" />
      </div>
    </section>
  );
}
