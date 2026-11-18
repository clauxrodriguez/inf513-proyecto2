export function calcularImporteValorado(documento, item) {
  const arancel = Number(documento.arancel_referencial) || 0;
  const unidad = documento.unidad_cobro;
  const cantidad = unidad === 'FOJA'
    ? Number(item.cantidad_paginas || 0)
    : unidad === 'MATERIA'
      ? Number(item.cantidad_materias || 0)
      : 1;
  return Math.round((arancel * cantidad + Number.EPSILON) * 100) / 100;
}

export function calcularMontoTotal(documentos, seleccionados) {
  return seleccionados.reduce((total, item) => {
    const documento = documentos.find((doc) => Number(doc.id) === Number(item.catalogo_documento_id));
    return total + (documento ? calcularImporteValorado(documento, item) : 0);
  }, 0);
}
