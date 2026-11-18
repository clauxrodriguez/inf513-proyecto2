import { useState } from 'react';
import { anularCertificado, registrarEntregaConstancia } from '../../api/certificadosApi';
import { mensajeError } from '../../api/despachoApi';
import Alerta from '../ui/Alerta';
import Modal from '../ui/Modal';

const ACCIONES = {
  anular: {
    titulo: 'Anular certificado',
    etiqueta: 'Motivo de la anulación *',
    ayuda: 'La anulación es definitiva e irreversible: el QR dejará de validar el documento y se notificará al solicitante.',
    boton: 'Anular definitivamente',
    estilo: 'bg-red-700 hover:bg-red-800',
    minimo: 10,
    multilinea: true,
    ejecutar: anularCertificado,
  },
  entregar: {
    titulo: 'Entrega de constancia (Sección III)',
    etiqueta: 'N° de formulario entregado *',
    ayuda: 'Se registra una sola vez con la fecha de hoy; luego no puede modificarse.',
    boton: 'Registrar entrega',
    estilo: 'bg-emerald-700 hover:bg-emerald-800',
    minimo: 1,
    multilinea: false,
    ejecutar: registrarEntregaConstancia,
  },
};

/** SF-3 (anulación definitiva) y SF-1 (entrega de la constancia de descuento en ventanilla). */
export default function ModalAccionCertificado({ accion, certificado, onCerrar, onHecho }) {
  const config = ACCIONES[accion];
  const [valor, setValor] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState('');

  const confirmar = async (event) => {
    event.preventDefault();
    setError('');
    setEnviando(true);
    try {
      const res = await config.ejecutar(certificado.id, valor.trim());
      onHecho(res.data.message);
    } catch (err) {
      setError(mensajeError(err, 'No se pudo completar la operación.'));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal titulo={`${config.titulo} ${certificado.numero}`}>
      <form onSubmit={confirmar} className="space-y-4 text-sm">
        <p className="text-slate-600">{certificado.tramite_codigo} · {certificado.titular}</p>
        <label className="block">
          <span className="mb-1 block font-medium text-slate-700">{config.etiqueta}</span>
          {config.multilinea
            ? <textarea required minLength={config.minimo} maxLength={300} rows="3" value={valor} onChange={(e) => setValor(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2" />
            : <input required maxLength={30} value={valor} onChange={(e) => setValor(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2" />}
          <span className="mt-1 block text-xs text-slate-500">{config.ayuda}</span>
        </label>
        <Alerta mensaje={error} />
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onCerrar} disabled={enviando} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-50">Cancelar</button>
          <button type="submit" disabled={enviando} className={`rounded-lg px-4 py-2 font-medium text-white disabled:opacity-50 ${config.estilo}`}>
            {enviando ? 'Procesando...' : config.boton}
          </button>
        </div>
      </form>
    </Modal>
  );
}
