import { useCallback, useEffect, useState } from 'react';
import { Clock, RefreshCw } from 'lucide-react';
import { mensajeError } from '../api/despachoApi';
import { atenderAlerta, obtenerMonitorSla } from '../api/slaApi';
import ConfiguracionSla from '../components/sla/ConfiguracionSla';
import DesgloseFases from '../components/sla/DesgloseFases';
import PanelAlertas from '../components/sla/PanelAlertas';
import TablaSemaforo from '../components/sla/TablaSemaforo';
import TarjetasResumenSla from '../components/sla/TarjetasResumenSla';
import Alerta from '../components/ui/Alerta';
import { useAuth } from '../context/AuthContext';

const ACTUALIZACION_MS = 60_000;

/** CU7 - Monitor SLA de 72 horas hábiles: semáforo, alertas, desglose por fase y parametrización. */
export default function MonitoreoSla() {
  const { user } = useAuth();
  const [datos, setDatos] = useState(null);
  const [incluirAtendidas, setIncluirAtendidas] = useState(false);
  const [filtroSemaforo, setFiltroSemaforo] = useState('');
  const [filtroTipo, setFiltroTipo] = useState('');
  const [activo, setActivo] = useState(null);
  const [version, setVersion] = useState(0);
  const [atendiendo, setAtendiendo] = useState(null);
  const [mensaje, setMensaje] = useState('');
  const [error, setError] = useState('');

  const cargar = useCallback(async () => {
    try {
      const res = await obtenerMonitorSla(incluirAtendidas);
      setDatos(res.data);
      setVersion((v) => v + 1);
      if (res.data.alertas_nuevas > 0) setMensaje(`Se generaron ${res.data.alertas_nuevas} alerta(s) SLA nueva(s) y se notificó a Recepción.`);
    } catch (err) {
      setError(mensajeError(err, 'No se pudo cargar el monitor SLA.'));
    }
  }, [incluirAtendidas]);

  useEffect(() => {
    cargar();
    const intervalo = setInterval(cargar, ACTUALIZACION_MS);
    return () => clearInterval(intervalo);
  }, [cargar]);

  const atender = async (alerta) => {
    setError('');
    setMensaje('');
    setAtendiendo(alerta.id);
    try {
      setMensaje((await atenderAlerta(alerta.id)).data.message);
      await cargar();
    } catch (err) {
      setError(mensajeError(err, 'No se pudo marcar la alerta como atendida.'));
    } finally {
      setAtendiendo(null);
    }
  };

  if (!datos) return error ? <Alerta mensaje={error} /> : <p className="text-sm text-slate-500">Cargando monitor SLA...</p>;

  const general = datos.configuracion.find((c) => c.tipo === null);
  const tramites = datos.tramites.filter((t) => (!filtroSemaforo || t.semaforo === filtroSemaforo) && (!filtroTipo || t.tipo === filtroTipo));

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-bold text-slate-800"><Clock className="h-5 w-5 text-blue-900" />Monitoreo SLA ({general?.plazo_horas ?? 72} horas hábiles)</h1>
          <p className="text-sm text-slate-500">
            Cómputo de lunes a viernes; el reloj se detiene mientras el trámite está observado y al concluir.
            Alerta preventiva al {general?.porcentaje_alerta ?? 75}% ({general?.umbral_alerta_horas ?? 54} h) y vencida al 100%. Se actualiza cada minuto.
          </p>
        </div>
        <button type="button" onClick={cargar} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-white">
          <RefreshCw className="h-4 w-4" />Actualizar
        </button>
      </header>

      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />
      <TarjetasResumenSla resumen={datos.resumen} />

      <PanelAlertas alertas={datos.alertas} puedeAtender={user?.rol === 'RECEPCION'} incluirAtendidas={incluirAtendidas}
        atendiendo={atendiendo} onCambiarFiltro={setIncluirAtendidas} onAtender={atender} onSeleccionar={setActivo} />

      <section className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-lg font-semibold text-slate-800">Trámites en curso ({tramites.length})</h2>
          <div className="flex flex-wrap gap-2 text-sm">
            <select value={filtroSemaforo} onChange={(e) => setFiltroSemaforo(e.target.value)} className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5">
              <option value="">Todos los semáforos</option>
              <option value="ROJO">Rojo · vencido</option>
              <option value="AMARILLO">Amarillo · alerta</option>
              <option value="VERDE">Verde · normal</option>
            </select>
            <select value={filtroTipo} onChange={(e) => setFiltroTipo(e.target.value)} className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5">
              <option value="">Todos los tipos</option>
              <option value="LEGALIZACION">Legalización</option>
              <option value="DESCUENTO_DIPLOMADO">Descuento de diplomado</option>
            </select>
          </div>
        </div>
        <TablaSemaforo tramites={tramites} activo={activo} onSeleccionar={setActivo} />
      </section>

      {activo && <DesgloseFases tramiteId={activo} version={version} onCerrar={() => setActivo(null)} />}

      <ConfiguracionSla configuracion={datos.configuracion} esAdministrador={user?.rol === 'ADMINISTRADOR'}
        onActualizado={async (texto) => { setMensaje(texto); await cargar(); }} />
    </div>
  );
}
