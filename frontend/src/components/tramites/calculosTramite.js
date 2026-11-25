/** Mismo cálculo que fn_importe_esperado: tarifa × fojas, × materias o × 1 por documento. */
export function calcularImporte(documento, item) {
  const tarifa = Number(documento.tarifa) || 0;
  const cantidad = documento.tipo_cobro === 'FOJA'
    ? Number(item.cantidad_paginas || 0)
    : documento.tipo_cobro === 'MATERIA'
      ? Number(item.cantidad_materias || 0)
      : 1;
  return Math.round((tarifa * cantidad + Number.EPSILON) * 100) / 100;
}

export function calcularMontoTotal(documentos, seleccionados) {
  return seleccionados.reduce((total, item) => {
    const documento = documentos.find((doc) => Number(doc.id) === Number(item.catalogo_documento_id));
    return total + (documento ? calcularImporte(documento, item) : 0);
  }, 0);
}

export const moneda = (valor) => `Bs ${Number(valor || 0).toFixed(2)}`;

const ESTADOS = {
  SOLICITADO_ORIGEN: 'Solicitado (origen)', EN_FIRMA_ORIGEN: 'En firma de origen', INGRESADO: 'Ingresado a Ventanilla',
  EN_REVISION: 'En revisión', OBSERVADO: 'Observado', EN_BANDEJA_DESPACHO: 'En despacho', FIRMADO_DIGITALMENTE: 'Firmado',
  DERIVADO_RECTORADO: 'En Rectorado', CONCLUIDO: 'Concluido', RECHAZADO: 'Rechazado',
};
export const nombreEstado = (estado) => ESTADOS[estado] || estado;

export const UNIDAD_COBRO = { FOJA: 'por foja', MATERIA: 'por materia', DOCUMENTO: 'por documento' };

export const mensajeErrorApi = (err, respaldo) => (err.response?.data?.errors
  ? Object.values(err.response.data.errors).flat().join(' ')
  : err.response?.data?.message || respaldo);
