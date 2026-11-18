import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { listarTramites, validarDocumento } from '../api/tramitesApi';
import { useAuth } from '../context/AuthContext';
import Alerta from '../components/ui/Alerta';
import TablaExpedientes from '../components/tramites/TablaExpedientes';

export default function ListaTramites() {
  const { user } = useAuth();
  const location = useLocation();
  const esEstudiante = user?.rol === 'ESTUDIANTE';
  const esRecepcion = user?.rol === 'RECEPCION';
  const [tramites, setTramites] = useState([]);
  const [error, setError] = useState('');
  const [mensaje, setMensaje] = useState(location.state?.mensaje || '');
  const [cargando, setCargando] = useState(true);
  const cargar = useCallback(async () => {
    try { const res = await listarTramites(); setTramites(res.data); }
    catch (err) { setError(err.response?.data?.message || 'No se pudieron cargar los trámites.'); }
    finally { setCargando(false); }
  }, []);
  useEffect(() => { cargar(); }, [cargar]);
  const validar = async (id, payload) => {
    setError(''); setMensaje('');
    try {
      const res = await validarDocumento(id, payload);
      setMensaje(res.data.message); await cargar(); return true;
    } catch (err) {
      return err.response?.data?.errors
        ? Object.values(err.response.data.errors).flat().join(' ')
        : err.response?.data?.message || 'No se pudo validar el valorado y los sellos.';
    }
  };
  const rutaNuevo = esEstudiante ? '/solicitar' : '/tramites/nuevo';

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div><h1 className="text-xl font-bold text-slate-800">{esEstudiante ? 'Mis Trámites' : 'Bandeja de Trámites (CU3)'}</h1>
          <p className="text-sm text-slate-500">{esEstudiante ? 'Consulta el estado y los folios de tus solicitudes.' : 'Registro, revisión y seguimiento de expedientes.'}</p></div>
        {(esRecepcion || esEstudiante) && <Link to={rutaNuevo} className="bg-blue-900 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm font-medium">Nuevo Trámite</Link>}
      </header>
      {cargando ? <p className="text-sm text-slate-500">Cargando trámites...</p> : <TablaExpedientes
        tramites={tramites}
        esRecepcion={esRecepcion}
        onValidar={validar}
        rutaDetalle={esEstudiante ? '/mis-tramites' : '/tramites'}
      />}
      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />
    </div>
  );
}
