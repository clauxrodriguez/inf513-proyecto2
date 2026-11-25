/**
 * Exportación desde el navegador, sin dependencias:
 *  - Excel: CSV en UTF-8 con BOM y separador ";" (Excel en español lo abre en columnas).
 *  - PDF: diálogo de impresión del navegador ("Guardar como PDF"), con estilos @media print.
 */
const celda = (valor) => {
  const texto = valor === null || valor === undefined ? '' : String(valor);
  return /[";\n]/.test(texto) ? `"${texto.replaceAll('"', '""')}"` : texto;
};

/**
 * @param {string} nombre  nombre del archivo sin extensión
 * @param {Array<[string, string]>} columnas  [clave, encabezado]
 * @param {Array<Record<string, unknown>>} filas
 */
export function exportarExcel(nombre, columnas, filas) {
  const lineas = [
    columnas.map(([, encabezado]) => celda(encabezado)).join(';'),
    ...filas.map((fila) => columnas.map(([clave]) => celda(fila[clave])).join(';')),
  ];
  const blob = new Blob(['﻿', lineas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const enlace = document.createElement('a');
  enlace.href = url;
  enlace.download = `${nombre}.csv`;
  enlace.click();
  URL.revokeObjectURL(url);
}

export function exportarPdf() {
  window.print();
}
