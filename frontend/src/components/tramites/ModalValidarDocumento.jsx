import { useState } from 'react';
import Alerta from '../ui/Alerta';
import Modal from '../ui/Modal';

const moneda = (valor) => `${Number(valor || 0).toFixed(2)} Bs`;

/**
 * Recepción confirma las firmas precedentes (RN-04) y registra el valorado de caja (RF-07) de un documento.
 * onConfirmar(documentoId, payload) debe devolver true si se guardó, o un mensaje de error.
 */
export default function ModalValidarDocumento({ documento, onConfirmar, onCerrar }) {
  const [nroValorado, setNroValorado] = useState(documento.nro_valorado || '');
  const [importe, setImporte] = useState(documento.nro_valorado ? String(documento.importe_valorado || '') : '');
  const [error, setError] = useState('');
  const [guardando, setGuardando] = useState(false);

  const confirmar = async (event) => {
    event.preventDefault();
    setError('');
    setGuardando(true);
    const resultado = await onConfirmar(documento.id, {
      firmas_verificadas: true,
      nro_valorado: nroValorado.trim() || null,
      importe_valorado: importe ? Number(importe) : null,
    });
    setGuardando(false);
    if (resultado === true) onCerrar();
    else setError(resultado || 'No se pudo validar el documento.');
  };

  return (
    <Modal titulo={`Validar ${documento.documento_nombre || 'documento'}`}>
      <form onSubmit={confirmar} className="space-y-3 text-sm">
        <p className="text-slate-600">Confirme las firmas y sellos precedentes y registre el valorado de caja que adjunta el documento.</p>
        <p className="rounded-lg bg-slate-50 p-2 text-xs text-slate-600">
          Arancel esperado: <span className="font-semibold">{moneda(documento.arancel_esperado)}</span>
          {documento.cantidad_paginas ? ` · ${documento.cantidad_paginas} página(s)` : ''}
          {documento.cantidad_materias ? ` · ${documento.cantidad_materias} materia(s)` : ''}
        </p>
        <label className="block">
          <span className="mb-1 block font-medium text-slate-700">N° de valorado *</span>
          <input required maxLength={40} value={nroValorado} onChange={(e) => setNroValorado(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2" />
        </label>
        <label className="block">
          <span className="mb-1 block font-medium text-slate-700">Importe (Bs) *</span>
          <input type="number" required min="0.01" step="0.01" value={importe} onChange={(e) => setImporte(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2" />
        </label>
        <p className="text-xs text-slate-500">Al confirmar se registran las firmas precedentes como verificadas.</p>
        <Alerta mensaje={error} />
        <div className="flex justify-end gap-2 pt-1">
          <button type="button" onClick={onCerrar} disabled={guardando} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-700">Cancelar</button>
          <button type="submit" disabled={guardando} className="rounded-lg bg-blue-900 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-50">
            {guardando ? 'Validando...' : 'Confirmar validación'}
          </button>
        </div>
      </form>
    </Modal>
  );
}
