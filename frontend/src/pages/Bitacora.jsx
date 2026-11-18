import { useCallback, useEffect, useState } from 'react';
import { obtenerBitacora } from '../api/cuatroApi';
import Alerta from '../components/ui/Alerta';

export default function Bitacora() {
  const [resultado, setResultado] = useState(null);
  const [error, setError] = useState('');
  const [tramiteId, setTramiteId] = useState('');
  const [pagina, setPagina] = useState(1);
  const [cargando, setCargando] = useState(true);
  const cargar = useCallback(async () => {
    setCargando(true);
    try {
      const res = await obtenerBitacora({
        ...(tramiteId ? { tramite_id: tramiteId } : {}),
        pagina,
        per_page: 50,
      });
      setResultado(res.data);
      setError('');
    } catch (err) {
      setError(err.response?.data?.message || 'No se pudo consultar la bitácora.');
    } finally {
      setCargando(false);
    }
  }, [tramiteId, pagina]);

  useEffect(() => {
    let vigente = true;
    obtenerBitacora({
      ...(tramiteId ? { tramite_id: tramiteId } : {}),
      pagina,
      per_page: 50,
    })
      .then((res) => {
        if (vigente) {
          setResultado(res.data);
          setError('');
        }
      })
      .catch((err) => {
        if (vigente) setError(err.response?.data?.message || 'No se pudo consultar la bitácora.');
      })
      .finally(() => {
        if (vigente) setCargando(false);
      });
    return () => { vigente = false; };
  }, [tramiteId, pagina]);
  const registros = resultado?.registros?.data || [];

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-xl font-bold text-slate-800">Bitácora inmutable y accesos</h1>
        <p className="text-sm text-slate-500">Historial cronológico, cadena SHA-256 e inicios de sesión.</p>
      </header>
      {resultado && <div className={`rounded-lg border p-4 text-sm font-medium ${resultado.integridad ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800'}`}>
        {resultado.integridad
          ? 'Integridad verificada: la cadena de hashes no presenta alteraciones.'
          : `ALERTA: la cadena presenta una alteración. Primer registro afectado: ${resultado.primer_id_alterado ?? 'no identificado'}.`}
      </div>}
      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4">
        <label className="text-sm text-slate-700">Filtrar por ID de trámite
          <input type="number" min="1" value={tramiteId} onChange={(event) => { setTramiteId(event.target.value); setPagina(1); }}
            className="mt-1 block rounded-lg border border-slate-300 px-3 py-2" />
        </label>
        <button type="button" onClick={cargar} className="rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">Actualizar</button>
      </div>
      {error && <Alerta mensaje={error} />}
      {cargando ? <p className="text-sm text-slate-500">Consultando bitácora...</p> : <>
        <section className="space-y-3">
          <h2 className="text-lg font-semibold text-slate-800">Eventos de trámites</h2>
          <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase text-slate-600"><tr>
                <th className="px-3 py-3">Fecha</th><th className="px-3 py-3">Trámite</th><th className="px-3 py-3">Responsable</th>
                <th className="px-3 py-3">Operación</th><th className="px-3 py-3">Detalle</th><th className="px-3 py-3">Hash anterior</th><th className="px-3 py-3">Hash actual</th>
              </tr></thead>
              <tbody className="divide-y divide-slate-100">
                {registros.map((registro) => <tr key={registro.id} className="align-top">
                  <td className="whitespace-nowrap px-3 py-3 text-xs text-slate-600">{registro.fecha}</td>
                  <td className="px-3 py-3 text-xs">{registro.tramite_id ?? '—'}</td>
                  <td className="px-3 py-3 text-xs">{registro.usuario_nombre || 'Sistema'}<span className="block text-slate-500">{registro.rol || '—'}</span></td>
                  <td className="px-3 py-3"><span className="font-medium">{registro.accion}</span><span className="block text-xs text-slate-500">{registro.entidad} · {registro.entidad_id}</span></td>
                  <td className="max-w-[260px] px-3 py-3"><pre className="whitespace-pre-wrap break-words text-xs text-slate-600">{JSON.stringify(registro.detalle, null, 2)}</pre></td>
                  <td className="max-w-[220px] break-all px-3 py-3 font-mono text-[10px] text-slate-500">{registro.hash_anterior}</td>
                  <td className="max-w-[220px] break-all px-3 py-3 font-mono text-[10px] text-slate-500">{registro.hash_actual}</td>
                </tr>)}
                {!registros.length && <tr><td colSpan="7" className="px-4 py-8 text-center text-sm text-slate-500">No hay eventos en este filtro.</td></tr>}
              </tbody>
            </table>
          </div>
          <div className="flex items-center justify-between text-sm text-slate-600">
            <span>Página {resultado?.registros?.current_page || 1} de {resultado?.registros?.last_page || 1}</span>
            <div className="flex gap-2">
              <button type="button" disabled={pagina <= 1} onClick={() => setPagina((actual) => actual - 1)} className="rounded border px-3 py-1 disabled:opacity-40">Anterior</button>
              <button type="button" disabled={pagina >= (resultado?.registros?.last_page || 1)} onClick={() => setPagina((actual) => actual + 1)} className="rounded border px-3 py-1 disabled:opacity-40">Siguiente</button>
            </div>
          </div>
        </section>

        <section className="space-y-3">
          <h2 className="text-lg font-semibold text-slate-800">Accesos recientes</h2>
          <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase text-slate-600"><tr>
                <th className="px-3 py-3">Fecha</th><th className="px-3 py-3">Usuario / intento</th><th className="px-3 py-3">Acción</th><th className="px-3 py-3">Ruta</th><th className="px-3 py-3">IP</th>
              </tr></thead>
              <tbody className="divide-y divide-slate-100">
                {(resultado?.accesos || []).map((acceso) => <tr key={acceso.id}>
                  <td className="whitespace-nowrap px-3 py-3 text-xs">{acceso.fecha}</td>
                  <td className="px-3 py-3 text-xs">{acceso.usuario_nombre || acceso.email_intento || 'Anónimo'}</td>
                  <td className="px-3 py-3 text-xs">{acceso.accion}</td><td className="px-3 py-3 text-xs">{acceso.metodo} {acceso.ruta}</td>
                  <td className="px-3 py-3 text-xs">{acceso.ip_origen}</td>
                </tr>)}
                {!resultado?.accesos?.length && <tr><td colSpan="5" className="px-4 py-8 text-center text-sm text-slate-500">No hay accesos registrados.</td></tr>}
              </tbody>
            </table>
          </div>
        </section>
      </>}
    </div>
  );
}
