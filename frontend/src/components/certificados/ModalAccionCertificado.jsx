import { useState } from 'react';
import { anularCertificado } from '../../api/certificadosApi';
import { mensajeError } from '../../api/despachoApi';
import Alerta from '../ui/Alerta';
import Modal from '../ui/Modal';

/** Anulación definitiva del certificado: el motivo queda en la bitácora y se notifica al solicitante. */
export default function ModalAccionCertificado({ certificado, onCerrar, onHecho }) {
  const [motivo, setMotivo] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState('');

  const confirmar = async (event) => {
    event.preventDefault();
    if (motivo.trim().length < 10) { setError('Explique el motivo de la anulación (al menos 10 caracteres).'); return; }
    setError('');
    setEnviando(true);
    try {
      const res = await anularCertificado(certificado.id, motivo.trim());
      onHecho(res.data.message);
    } catch (err) {
      setError(mensajeError(err, 'No se pudo anular el certificado.'));
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal titulo={`Anular certificado ${certificado.numero}`}>
      <form onSubmit={confirmar} noValidate className="space-y-4 text-sm">
        <p className="text-slate-600">{certificado.tramite_codigo} · {certificado.titular}</p>
        <label className="block">
          <span className="mb-1 block font-medium text-slate-700">Motivo de la anulación *</span>
          <textarea maxLength={300} rows="3" value={motivo} onChange={(e) => setMotivo(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2" />
          <span className="mt-1 block text-xs text-slate-500">La anulación es definitiva: el QR dejará de validar el documento y se notificará al solicitante.</span>
        </label>
        <Alerta mensaje={error} />
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onCerrar} disabled={enviando} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-50">Cancelar</button>
          <button type="submit" disabled={enviando} className="rounded-lg bg-red-700 px-4 py-2 font-medium text-white hover:bg-red-800 disabled:opacity-50">
            {enviando ? 'Procesando...' : 'Anular definitivamente'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
