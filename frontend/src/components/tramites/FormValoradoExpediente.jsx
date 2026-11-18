import { AlertTriangle, X } from 'lucide-react';
import { calcularImporteValorado } from './calculosTramite';

const moneda = (valor) => `${Number(valor || 0).toFixed(2)} Bs`;

export default function FormValoradoExpediente({
  tipo, documentos, seleccionados, setSeleccionados, esRecepcion, archivoDescuento, setArchivoDescuento,
}) {
  const actualizar = (indice, cambios) => setSeleccionados(
    seleccionados.map((item, index) => index === indice ? { ...item, ...cambios } : item),
  );
  if (tipo === 'DESCUENTO_DIPLOMADO') {
    return (
      <section className="bg-white p-5 rounded-xl border border-slate-200 space-y-3">
        <h2 className="font-bold text-slate-800">Respaldo del expediente</h2>
        <div><label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="respaldo-descuento">PDF de respaldo *</label>
          <input id="respaldo-descuento" type="file" required accept="application/pdf,.pdf"
            onChange={(e) => setArchivoDescuento(e.target.files?.[0] || null)}
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs" />
          {archivoDescuento && <p className="text-xs text-slate-500 mt-1">{archivoDescuento.name}</p>}
        </div>
      </section>
    );
  }
  return (
    <section className="bg-white p-5 rounded-xl border border-slate-200 space-y-4">
      <div>
        <h2 className="font-bold text-slate-800">Valorados y archivos por documento</h2>
        <p className="text-xs text-slate-500 mt-1">El importe se calcula con el arancel referencial y la unidad de cobro.</p>
      </div>
      {seleccionados.map((item, index) => {
        const documento = documentos.find((doc) => Number(doc.id) === Number(item.catalogo_documento_id));
        if (!documento) return null;
        const importe = calcularImporteValorado(documento, item);
        const importeInsuficiente = item.importe_valorado !== '' && Number(item.importe_valorado) < importe;
        return (
          <article key={documento.id} className="border border-slate-200 rounded-lg p-4 space-y-3">
            <h3 className="font-medium text-slate-800">{documento.codigo} — {documento.nombre}</h3>
            <div className="grid sm:grid-cols-2 gap-3">
              <div><label className="block text-xs font-medium text-slate-700 mb-1" htmlFor={`nro-valorado-${documento.id}`}>N° de valorado</label>
                <input id={`nro-valorado-${documento.id}`} maxLength={40} value={item.nro_valorado}
                  onChange={(e) => actualizar(index, { nro_valorado: e.target.value })}
                  className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm" /></div>
              <div><label className="block text-xs font-medium text-slate-700 mb-1" htmlFor={`importe-valorado-${documento.id}`}>Importe del valorado (Bs)</label>
                <input id={`importe-valorado-${documento.id}`} type="number" min="0.01" step="0.01"
                  value={item.importe_valorado} placeholder={importe.toFixed(2)}
                  onChange={(e) => actualizar(index, { importe_valorado: e.target.value })}
                  className={`w-full border rounded-lg px-3 py-2 text-sm ${importeInsuficiente ? 'border-red-400 bg-red-50' : 'border-slate-300'}`} />
              </div>
            </div>
            {importeInsuficiente && <p role="alert" className="flex items-center gap-2 text-xs text-red-700 bg-red-50 border border-red-200 rounded-lg p-2">
              <AlertTriangle className="w-4 h-4 shrink-0" />Importe menor al arancel referencial calculado ({moneda(importe)}).
            </p>}
            {esRecepcion && <label className="flex items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" checked={Boolean(item.firmas_verificadas)} onChange={(e) => actualizar(index, { firmas_verificadas: e.target.checked })}
                className="rounded border-slate-300 text-blue-900 focus:ring-blue-800" />
              Firmas y sellos precedentes verificados
            </label>}
            <div><label className="block text-xs font-medium text-slate-700 mb-1" htmlFor={`archivo-doc-${documento.id}`}>Archivo digital (PDF o imagen)</label>
              <input id={`archivo-doc-${documento.id}`} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                onChange={(e) => actualizar(index, { archivo: e.target.files?.[0] || null })}
                className="w-full border border-slate-300 rounded-lg px-3 py-2 text-xs" />
              {item.archivo && <div className="flex items-center justify-between gap-2 mt-1 text-xs text-slate-500">
                <span className="truncate">{item.archivo.name}</span>
                <button type="button" onClick={() => actualizar(index, { archivo: null })} aria-label={`Quitar ${item.archivo.name}`}
                  className="p-1 text-red-600 hover:bg-red-50 rounded"><X className="w-3.5 h-3.5" /></button>
              </div>}
            </div>
          </article>
        );
      })}
    </section>
  );
}
