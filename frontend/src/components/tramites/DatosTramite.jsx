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
            <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="destino">¿Dónde presentará los documentos? *</label>
            <select id="destino" required value={form.destino} aria-describedby="ayuda-destino"
              onChange={(e) => actualizar({ destino: e.target.value, pais_destino: e.target.value === 'NACIONAL' ? '' : form.pais_destino })} className={campo}>
              <option value="NACIONAL">En Bolivia</option>
              <option value="EXTERIOR">En el exterior</option>
            </select>
            <p id="ayuda-destino" className="mt-1 text-xs text-slate-500">
              {form.destino === 'EXTERIOR' ? 'Requiere además el visado del Rectorado, para luego tramitar la Apostilla.' : 'Legalización para uso dentro del país.'}
            </p>
          </div>
        )}
        {esLegalizacion && form.destino === 'EXTERIOR' && (
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="pais-destino">País de destino *</label>
            <input id="pais-destino" required maxLength={80} value={form.pais_destino} onChange={(e) => actualizar({ pais_destino: e.target.value })} className={campo} />
          </div>
        )}
        {esLegalizacion && (
          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="institucion-destino">Institución que recibirá los documentos</label>
            <input id="institucion-destino" maxLength={200} value={form.institucion_destino} aria-describedby="ayuda-institucion"
              onChange={(e) => actualizar({ institucion_destino: e.target.value })} className={campo} />
            <p id="ayuda-institucion" className="mt-1 text-xs text-slate-500">Opcional. Se indica en el certificado (p. ej. «ante la Universidad de Salamanca»).</p>
          </div>
        )}
      </div>
    </section>
  );
}
