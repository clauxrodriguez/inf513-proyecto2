import { useState } from 'react';

const TIPOS = [
  ['SELLO_FALTANTE', 'Sello faltante'],
  ['FIRMA_FALTANTE', 'Firma faltante'],
  ['FOJA_ILEGIBLE', 'Foja ilegible'],
  ['PAGO_INSUFICIENTE', 'Pago insuficiente'],
  ['FOLIACION_INCORRECTA', 'Foliación incorrecta'],
  ['DOCUMENTO_EXTERNO', 'Documento externo a la UAGRM'],
  ['FUERA_DE_PLAZO', 'Fuera de plazo'],
  ['OTRO', 'Otro'],
];

export default function FormObservacion({ documentos, onSubmit, disabled }) {
  const [tipo, setTipo] = useState('SELLO_FALTANTE');
  const [documentoId, setDocumentoId] = useState('');
  const [descripcion, setDescripcion] = useState('');
  const [error, setError] = useState('');

  const guardar = async (event) => {
    event.preventDefault();
    if (descripcion.trim().length < 5) { setError('Describa la observación (al menos 5 caracteres).'); return; }
    setError('');
    const guardado = await onSubmit({ tipo, descripcion: descripcion.trim(), tramite_documento_id: documentoId || null });
    if (guardado) {
      setTipo('SELLO_FALTANTE');
      setDocumentoId('');
      setDescripcion('');
    }
  };

  return (
    <form onSubmit={guardar} noValidate className="space-y-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
      <h3 className="font-semibold text-slate-800">Registrar observación</h3>
      <div className="grid gap-3 md:grid-cols-2">
        <label className="text-sm text-slate-700">
          Categoría
          <select value={tipo} onChange={(event) => setTipo(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
            {TIPOS.map(([valor, etiqueta]) => <option key={valor} value={valor}>{etiqueta}</option>)}
          </select>
        </label>
        <label className="text-sm text-slate-700">
          Documento relacionado (opcional)
          <select value={documentoId} onChange={(event) => setDocumentoId(event.target.value)} className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2">
            <option value="">Trámite completo</option>
            {documentos.map((documento) => <option key={documento.id} value={documento.id}>{documento.documento_nombre}</option>)}
          </select>
        </label>
      </div>
      <label className="block text-sm text-slate-700">
        Descripción *
        <textarea maxLength={500} value={descripcion} onChange={(event) => setDescripcion(event.target.value)}
          className="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2" rows="3" />
      </label>
      {error && <p role="alert" className="text-xs text-red-700">{error}</p>}
      <div className="flex justify-end">
        <button type="submit" disabled={disabled} className="rounded-lg bg-amber-700 px-4 py-2 text-sm font-medium text-white hover:bg-amber-800 disabled:opacity-50">
          Registrar y observar
        </button>
      </div>
    </form>
  );
}
