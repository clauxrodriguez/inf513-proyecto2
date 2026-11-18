import { KeyRound } from 'lucide-react';

/**
 * Emisión del certificado digital de firma. La AC de prueba del microservicio ocupa el lugar de ADSIB:
 * genera el par de llaves y el certificado X.509, y lo guarda en un .p12 protegido con el PIN (el "token").
 */
export default function FormCertificado({ datos, form, setForm, onSubmit, enviando }) {
  const actualizar = (campo, valor) => setForm({ ...form, [campo]: valor });
  return (
    <form onSubmit={onSubmit} className="bg-white p-5 rounded-xl border border-slate-200 space-y-3 text-sm">
      <h2 className="font-bold text-slate-800 flex items-center gap-2"><KeyRound className="w-4 h-4 text-emerald-700" />Emitir Certificado Digital de Firma</h2>
      <p className="text-xs text-slate-500">
        Lo emite la AC de prueba del sistema (en producción, ADSIB). El certificado anterior del titular queda inactivo.
      </p>
      <div><label className="block font-medium text-slate-700 mb-1">Autoridad firmante *</label>
        <select required value={form.usuario_id} onChange={(e) => actualizar('usuario_id', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white">
          <option value="">Seleccione titular o suplente...</option>
          {[...datos.vicerrectores, ...datos.suplentes].map((u) => <option key={u.id} value={u.id}>{u.nombres} {u.apellidos}</option>)}
        </select>
      </div>
      <div><label className="block font-medium text-slate-700 mb-1">Vigencia *</label>
        <select value={form.dias_vigencia} onChange={(e) => actualizar('dias_vigencia', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5 bg-white">
          <option value="180">6 meses</option>
          <option value="365">1 año</option>
          <option value="730">2 años</option>
        </select>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div><label className="block font-medium text-slate-700 mb-1">PIN del certificado *</label>
          <input type="password" required minLength={6} maxLength={64} autoComplete="new-password" value={form.pin} onChange={(e) => actualizar('pin', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5" />
        </div>
        <div><label className="block font-medium text-slate-700 mb-1">Confirmar PIN *</label>
          <input type="password" required minLength={6} maxLength={64} autoComplete="new-password" value={form.pin_confirmation} onChange={(e) => actualizar('pin_confirmation', e.target.value)} className="w-full border border-slate-300 rounded-lg px-2.5 py-1.5" />
        </div>
      </div>
      <p className="text-xs text-slate-500">El PIN protege la llave privada; entréguelo de forma reservada al titular, que lo usará al firmar.</p>
      <button type="submit" disabled={enviando} className="flex items-center gap-2 bg-emerald-700 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg font-medium disabled:opacity-50"><KeyRound className="w-4 h-4" />{enviando ? 'Emitiendo...' : 'Emitir y Activar Certificado'}</button>
    </form>
  );
}
