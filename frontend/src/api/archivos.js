import { mensajeErrorBlob } from './despachoApi';

/**
 * Abre en una pestaña nueva un archivo que la API entrega con la sesión del usuario (los documentos
 * de los trámites no son públicos). La pestaña se abre de inmediato, dentro del clic, para que el
 * navegador no la bloquee; luego se carga el archivo.
 * Devuelve null si todo salió bien o el mensaje de error.
 *
 * @param {() => Promise<import('axios').AxiosResponse<Blob>>} obtener  llamada con responseType 'blob'
 */
export async function abrirArchivo(obtener) {
  const ventana = window.open('', '_blank');
  ventana?.document.write('<p style="font-family:sans-serif;color:#475569;padding:24px">Cargando documento...</p>');
  try {
    const respuesta = await obtener();
    const tipo = respuesta.headers?.['content-type'] || 'application/pdf';
    const url = URL.createObjectURL(new Blob([respuesta.data], { type: tipo }));
    if (ventana) ventana.location.href = url;
    else window.open(url, '_blank');
    setTimeout(() => URL.revokeObjectURL(url), 120_000);
    return null;
  } catch (err) {
    ventana?.close();
    return mensajeErrorBlob(err, 'No se pudo abrir el documento.');
  }
}
