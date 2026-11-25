import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { listarTramites } from '../api/tramitesApi';
import { useAuth } from '../context/AuthContext';
import Alerta from '../components/ui/Alerta';
import TablaExpedientes from '../components/tramites/TablaExpedientes';
import { mensajeErrorApi } from '../components/tramites/calculosTramite';

/** Ventanilla: trámites que ya completaron (o están completando) la firma en origen. */
const ESTADOS_VENTANILLA = 'INGRESADO,EN_REVISION,OBSERVADO,EN_BANDEJA_DESPACHO,FIRMADO_DIGITALMENTE,DERIVADO_RECTORADO,CONCLUIDO,RECHAZADO';

export default function ListaTramites() {
  const { user } = useAuth();
  const location = useLocation();
  const esEstudiante = user?.rol === 'ESTUDIANTE';
  const [tramites, setTramites] = useState([]);
  const [error, setError] = useState('');
  const [cargando, setCargando] = useState(true);
  const mensaje = location.state?.mensaje || '';

  useEffect(() => {
    let vigente = true;
    listarTramites(esEstudiante ? {} : { estado: ESTADOS_VENTANILLA })
      .then((res) => { if (vigente) setTramites(res.data); })
      .catch((err) => { if (vigente) setError(mensajeErrorApi(err, 'No se pudieron cargar los trámites.')); })
      .finally(() => { if (vigente) setCargando(false); });
    return () => { vigente = false; };
  }, [esEstudiante]);

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-800">{esEstudiante ? 'Mis Trámites' : 'Ventanilla de Legalizaciones'}</h1>
          <p className="text-sm text-slate-500">
            {esEstudiante
              ? 'Siga el avance de sus solicitudes: firmas en origen, revisión, despacho y certificado.'
              : 'Recepción, cotejo de firmas, recibos de caja y compilación de expedientes.'}
          </p>
        </div>
        {esEstudiante && <Link to="/mis-tramites/nuevo" className="bg-blue-900 hover:bg-blue-800 text-white px-4 py-2 rounded-lg text-sm font-medium">Nuevo trámite</Link>}
      </header>
      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />
      {cargando ? <p className="text-sm text-slate-500">Cargando trámites...</p>
        : <TablaExpedientes tramites={tramites} rutaDetalle={esEstudiante ? '/mis-tramites' : '/tramites'} />}
    </div>
  );
}
