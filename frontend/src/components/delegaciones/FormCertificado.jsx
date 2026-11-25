import { KeyRound } from 'lucide-react';

const campo = 'w-full border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white';

/**
 * Emisión del certificado digital de firma (AC de prueba del microservicio; en producción, ADSIB):
 * par de llaves y certificado X.509 en un .p12 protegido con el PIN.
 */
export default function FormCertificado({ firmantes, form, setForm, onSubmit, enviando }) {
  const actualizar = (clave, valor) => setForm({ ...form, [clave]: valor });

  return (
    <form onSubmit={onSubmit} noValidate className="bg-white p-5 rounded-xl border border-slate-200 space-y-3 text-sm">
      <h2 className="font-bold text-slate-800 flex items-center gap-2"><KeyRound className="w-4 h-4 text-emerald-700" aria-hidden="true" />Emitir certificado digital de firma</h2>
      <p className="text-xs text-slate-500">Para las autoridades de Carrera, Decanato, Admisiones, DAGA, Vicerrectorado y Rectorado. El certificado anterior del titular queda inactivo.</p>
      <label className="block font-medium text-slate-700">Autoridad firmante *
        <select value={form.usuario_id} onChange={(e) => actualizar('usuario_id', e.target.value)} className={`mt-1 ${campo}`}>
          <option value="">Seleccione...</option>
          {firmantes.map((u) => <option key={u.id} value={u.id}>{u.apellidos}, {u.nombres} — {u.roles}</option>)}
        </select>
      </label>
      <label className="block font-medium text-slate-700">Vigencia *
        <select value={form.dias_vigencia} onChange={(e) => actualizar('dias_vigencia', e.target.value)} className={`mt-1 ${campo}`}>
          <option value="180">6 meses</option><option value="365">1 año</option><option value="730">2 años</option>
        </select>
      </label>
      <div className="grid grid-cols-2 gap-3">
        <label className="block font-medium text-slate-700">PIN *
          <input type="password" maxLength={64} autoComplete="new-password" value={form.pin} onChange={(e) => actualizar('pin', e.target.value)} className={`mt-1 ${campo}`} />
        </label>
        <label className="block font-medium text-slate-700">Confirmar PIN *
          <input type="password" maxLength={64} autoComplete="new-password" value={form.pin_confirmation} onChange={(e) => actualizar('pin_confirmation', e.target.value)} className={`mt-1 ${campo}`} />
        </label>
      </div>
      <p className="text-xs text-slate-500">El PIN protege la llave privada: entréguelo de forma reservada al titular.</p>
      <button type="submit" disabled={enviando} className="flex items-center gap-2 bg-emerald-700 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg font-medium disabled:opacity-50">
        <KeyRound className="w-4 h-4" aria-hidden="true" />{enviando ? 'Procesando...' : 'Emitir y activar certificado'}
      </button>
    </form>
  );
}
