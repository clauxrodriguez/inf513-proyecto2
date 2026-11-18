import { useCallback, useEffect, useState } from 'react';
import { Layers, RefreshCw, X } from 'lucide-react';
import { mensajeError, obtenerBandejaDespacho } from '../api/despachoApi';
import DetalleLegajo from '../components/despacho/DetalleLegajo';
import ModalFirma from '../components/despacho/ModalFirma';
import PanelHabilitacion from '../components/despacho/PanelHabilitacion';
import TablaBandeja from '../components/despacho/TablaBandeja';
import VisorPdf from '../components/despacho/VisorPdf';
import Alerta from '../components/ui/Alerta';

/** CU5 - Bandeja de Despacho y Firma Digital (Vicerrector/a titular o Suplente con delegación vigente). */
export default function Despacho() {
  const [datos, setDatos] = useState({ habilitacion: null, expedientes: [] });
  const [cargando, setCargando] = useState(true);
  const [seleccion, setSeleccion] = useState([]);
  const [activo, setActivo] = useState(null);
  const [recargaDetalle, setRecargaDetalle] = useState(0);
  const [aFirmar, setAFirmar] = useState(null);
  const [mensaje, setMensaje] = useState('');
  const [error, setError] = useState('');

  const cargar = useCallback(async () => {
    try {
      const res = await obtenerBandejaDespacho();
      setDatos(res.data);
      const ids = res.data.expedientes.map((exp) => exp.tramite_id);
      setSeleccion((previa) => previa.filter((id) => ids.includes(id)));
      setActivo((previo) => (ids.includes(previo) ? previo : null));
    } catch (err) {
      setError(mensajeError(err, 'No se pudo cargar la bandeja de despacho.'));
    } finally {
      setCargando(false);
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const puedeFirmar = Boolean(datos.habilitacion?.puede_firmar);
  const alternar = (id) => setSeleccion((previa) => (previa.includes(id) ? previa.filter((x) => x !== id) : [...previa, id]));
  const alternarTodos = () => setSeleccion((previa) => (previa.length === datos.expedientes.length ? [] : datos.expedientes.map((exp) => exp.tramite_id)));
  const abrirFirma = (ids) => {
    setError('');
    setMensaje('');
    setAFirmar(datos.expedientes.filter((exp) => ids.includes(exp.tramite_id)));
  };
  const alFirmar = async (texto) => {
    setAFirmar(null);
    setSeleccion([]);
    setActivo(null);
    setMensaje(texto);
    await cargar();
  };
  const alDevolver = async (texto) => {
    setActivo(null);
    setMensaje(texto);
    await cargar();
  };

  if (cargando) return <p className="text-sm text-slate-500">Cargando bandeja de despacho...</p>;

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-800">Bandeja de Despacho y Firma Digital (CU5)</h1>
          <p className="text-sm text-slate-500">Expedientes priorizados por urgencia y vencimiento del SLA de 72 horas hábiles · firma PAdES individual o por lote</p>
        </div>
        <button type="button" onClick={cargar} className="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 hover:bg-white">
          <RefreshCw className="h-4 w-4" />Actualizar
        </button>
      </header>

      <PanelHabilitacion habilitacion={datos.habilitacion} />
      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />

      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-slate-600">{datos.expedientes.length} expediente(s) en bandeja · {seleccion.length} seleccionado(s)</p>
        <button type="button" onClick={() => abrirFirma(seleccion)} disabled={!puedeFirmar || seleccion.length < 2}
          className="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-600 disabled:opacity-40"
          title="Seleccione dos o más expedientes conformes">
          <Layers className="h-4 w-4" />Firmar Lote Consolidado ({seleccion.length})
        </button>
      </div>

      <TablaBandeja
        expedientes={datos.expedientes}
        seleccion={seleccion}
        activo={activo}
        puedeFirmar={puedeFirmar}
        onAlternar={alternar}
        onAlternarTodos={alternarTodos}
        onRevisar={(id) => { setActivo(id); setMensaje(''); }}
        onFirmar={abrirFirma}
      />

      {activo && (
        <section className="space-y-3">
          <div className="flex items-center justify-between">
            <h2 className="text-lg font-semibold text-slate-800">Previsualización del legajo</h2>
            <button type="button" onClick={() => setActivo(null)} className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-slate-900">
              <X className="h-4 w-4" />Cerrar visor
            </button>
          </div>
          <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
            <VisorPdf tramiteId={activo} onCargado={() => setRecargaDetalle((n) => n + 1)} />
            <DetalleLegajo tramiteId={activo} recarga={recargaDetalle} puedeFirmar={puedeFirmar} onFirmar={abrirFirma} onDevuelto={alDevolver} />
          </div>
        </section>
      )}

      {aFirmar && (
        <ModalFirma expedientes={aFirmar} habilitacion={datos.habilitacion} onCerrar={() => setAFirmar(null)} onFirmado={alFirmar} />
      )}
    </div>
  );
}
