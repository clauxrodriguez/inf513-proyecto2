import { useState } from 'react';
import { KeyRound, MapPin, PenTool } from 'lucide-react';
import { firmarLote, mensajeError } from '../../api/despachoApi';
import Alerta from '../ui/Alerta';
import Modal from '../ui/Modal';
import { InsigniaCalidad } from './Insignias';

/**
 * Confirmación criptográfica: PIN del certificado y modalidad presencial o remota (SF-1).
 * Un expediente = firma individual; varios = firma consolidada por lote con una sola confirmación.
 */
export default function ModalFirma({ expedientes, habilitacion, onCerrar, onFirmado }) {
  const [pin, setPin] = useState('');
  const [modalidad, setModalidad] = useState('PRESENCIAL');
  const [ubicacion, setUbicacion] = useState('');
  const [enviando, setEnviando] = useState(false);
  const [error, setError] = useState('');
  const esLote = expedientes.length > 1;

  const confirmar = async (event) => {
    event.preventDefault();
    setError('');
    setEnviando(true);
    try {
      const res = await firmarLote({
        tramites: expedientes.map((exp) => exp.tramite_id),
        pin,
        modalidad,
        ubicacion: modalidad === 'REMOTA' ? ubicacion : null,
      });
      setPin('');
      onFirmado(res.data.message);
    } catch (err) {
      setError(mensajeError(err, 'No se pudo completar la firma digital.'));
      setPin('');
    } finally {
      setEnviando(false);
    }
  };

  return (
    <Modal titulo={esLote ? `Firmar Lote Consolidado (${expedientes.length} expedientes)` : 'Firmar Digitalmente'}>
      <form onSubmit={confirmar} className="space-y-4 text-sm">
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
          <p className="flex flex-wrap items-center gap-2 font-medium text-slate-800">
            Calidad del firmante <InsigniaCalidad calidad={habilitacion.calidad} />
          </p>
          <p className="mt-1 text-xs text-slate-600">{habilitacion.razon}</p>
          <p className="mt-1 text-xs text-slate-500">Certificado N° <span className="font-mono">{habilitacion.certificado?.numero_serie}</span></p>
        </div>

        <div>
          <p className="mb-1 font-medium text-slate-700">Expedientes a firmar</p>
          <ul className="max-h-32 space-y-1 overflow-y-auto text-xs text-slate-600">
            {expedientes.map((exp) => (
              <li key={exp.tramite_id} className="flex justify-between gap-2">
                <span className="font-medium text-slate-800">{exp.codigo}</span>
                <span>{exp.destino === 'EXTERIOR' ? 'Se derivará a Rectorado' : 'Listo para certificado QR'}</span>
              </li>
            ))}
          </ul>
        </div>

        <fieldset>
          <legend className="mb-1 font-medium text-slate-700">Modalidad</legend>
          <div className="grid grid-cols-2 gap-2">
            {[['PRESENCIAL', 'Presencial (campus)'], ['REMOTA', 'Remota (viaje / comisión)']].map(([valor, etiqueta]) => (
              <label key={valor} className={`cursor-pointer rounded-lg border px-3 py-2 text-xs ${modalidad === valor ? 'border-blue-800 bg-blue-50 font-medium text-blue-900' : 'border-slate-300 text-slate-600'}`}>
                <input type="radio" name="modalidad" value={valor} checked={modalidad === valor} onChange={() => setModalidad(valor)} className="sr-only" />
                {etiqueta}
              </label>
            ))}
          </div>
        </fieldset>

        {modalidad === 'REMOTA' && (
          <label className="block">
            <span className="mb-1 flex items-center gap-1 font-medium text-slate-700"><MapPin className="h-3.5 w-3.5" />Ubicación actual *</span>
            <input required maxLength={120} value={ubicacion} onChange={(e) => setUbicacion(e.target.value)} placeholder="Ej.: La Paz, Bolivia - Comisión oficial CEUB"
              className="w-full rounded-lg border border-slate-300 px-3 py-2" />
            <span className="mt-1 block text-xs text-slate-500">Se registra junto con su dirección IP en el lote de firma.</span>
          </label>
        )}

        <label className="block">
          <span className="mb-1 flex items-center gap-1 font-medium text-slate-700"><KeyRound className="h-3.5 w-3.5" />PIN del certificado digital *</span>
          <input type="password" required minLength={6} autoComplete="off" autoFocus value={pin} onChange={(e) => setPin(e.target.value)}
            className="w-full rounded-lg border border-slate-300 px-3 py-2 tracking-widest" />
        </label>

        <Alerta mensaje={error} />
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onCerrar} disabled={enviando} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-50">Cancelar</button>
          <button type="submit" disabled={enviando} className="inline-flex items-center gap-2 rounded-lg bg-blue-900 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-50">
            <PenTool className="h-4 w-4" />{enviando ? 'Firmando...' : 'Confirmar firma'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
