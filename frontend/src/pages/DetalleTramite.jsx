import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { obtenerDetalleTramite } from '../api/cuatroApi';
import Alerta from '../components/ui/Alerta';
import PanelRevision from '../components/tramites/PanelRevision';
import { useAuth } from '../context/AuthContext';

export default function DetalleTramite() {
  const { tramiteId } = useParams();
  const { user } = useAuth();
  const [tramite, setTramite] = useState(null);
  const [error, setError] = useState('');
  const [cargando, setCargando] = useState(true);
  const cargar = useCallback(async () => {
    try {
      const resultado = await obtenerDetalleTramite(tramiteId);
      setTramite(resultado.data);
      setError('');
    } catch (err) {
      setError(err.response?.data?.message || 'No se pudo cargar el detalle del trámite.');
    } finally {
      setCargando(false);
    }
  }, [tramiteId]);

  useEffect(() => {
    let vigente = true;
    obtenerDetalleTramite(tramiteId)
      .then((resultado) => {
        if (vigente) {
          setTramite(resultado.data);
          setError('');
        }
      })
      .catch((err) => {
        if (vigente) setError(err.response?.data?.message || 'No se pudo cargar el detalle del trámite.');
      })
      .finally(() => {
        if (vigente) setCargando(false);
      });
    return () => { vigente = false; };
  }, [tramiteId]);
  const rutaLista = user?.rol === 'ESTUDIANTE' ? '/mis-tramites' : '/tramites';

  if (cargando) return <p className="text-sm text-slate-500">Cargando expediente...</p>;
  if (!tramite) return <div className="space-y-4"><Alerta mensaje={error} /><Link to={rutaLista} className="text-sm text-blue-800 hover:underline">Volver a la bandeja</Link></div>;

  return (
    <div className="mx-auto max-w-6xl space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link to={rutaLista} className="text-sm text-blue-800 hover:underline">← Volver a trámites</Link>
          <h1 className="mt-2 text-xl font-bold text-slate-800">{tramite.codigo} · Revisión y seguimiento</h1>
          <p className="text-sm text-slate-500">{tramite.estudiante_nombre} · {tramite.carrera} · {tramite.estado?.replaceAll('_', ' ')}</p>
        </div>
        <Link to="/guia" className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-white">Consultar guía procesal</Link>
      </header>
      {error && <Alerta mensaje={error} />}
      <section className="grid gap-3 sm:grid-cols-3">
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <p className="text-xs uppercase text-slate-500">Destino</p>
          <p className="mt-1 font-semibold text-slate-800">{tramite.destino}{tramite.pais_destino ? ` · ${tramite.pais_destino}` : ''}</p>
        </div>
        <div className="rounded-xl border border-slate-200 bg-white p-4">
          <p className="text-xs uppercase text-slate-500">SLA</p>
          <p className="mt-1 font-semibold text-slate-800">{tramite.semaforo || 'Sin semáforo'} · {Number(tramite.horas_consumidas || 0).toFixed(1)} / {tramite.plazo_horas} h</p>
        </div>
        <div className={`rounded-xl border p-4 ${tramite.estado === 'OBSERVADO' ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'}`}>
          <p className="text-xs uppercase text-slate-500">Cómputo del plazo</p>
          <p className="mt-1 font-semibold text-slate-800">{tramite.estado === 'OBSERVADO' ? 'Detenido por observación' : 'En curso'}</p>
        </div>
      </section>
      <PanelRevision tramite={tramite} user={user} onRecargar={cargar} />
    </div>
  );
}
