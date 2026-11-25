/**
 * Cliente de Jacobitus Local (servicio de firma digital instalado en la PC del firmante).
 * La llave privada y el PIN no salen de la PC: el navegador envía el PDF a https://localhost:9000,
 * Jacobitus lo firma con el token o softoken y devuelve el PDF co-firmado (PAdES).
 *
 * IMPORTANTE: confirme las rutas y los nombres de campo con la documentación de su versión de Jacobitus;
 * están todos en RUTAS y en las funciones leer*() de este archivo.
 */
const BASE = (import.meta.env.VITE_JACOBITUS_URL || 'https://localhost:9000').replace(/\/$/, '');

export const RUTAS = {
  estado: '/',
  tokens: '/api/token/connected',
  datosToken: '/api/token/data',
  firmarPdf: '/api/token/firmar_pdf',
};

export class JacobitusError extends Error {}

/** Primer valor presente entre varias rutas posibles ("a.b.c") de un objeto. */
const campo = (objeto, rutas) => {
  for (const ruta of rutas) {
    const valor = ruta.split('.').reduce((actual, clave) => (actual == null ? undefined : actual[clave]), objeto);
    if (valor !== undefined && valor !== null) return valor;
  }
  return undefined;
};

/** Jacobitus suele envolver la respuesta en { finalizado, mensaje, datos }. */
const datosDe = (respuesta) => campo(respuesta, ['datos', 'data', 'result']) ?? respuesta;

async function llamar(ruta, { metodo = 'GET', cuerpo } = {}) {
  let respuesta;
  try {
    respuesta = await fetch(`${BASE}${ruta}`, {
      method: metodo,
      headers: cuerpo ? { 'Content-Type': 'application/json' } : undefined,
      body: cuerpo ? JSON.stringify(cuerpo) : undefined,
    });
  } catch {
    throw new JacobitusError(`No se pudo conectar con Jacobitus en ${BASE}. Verifique que esté iniciado y abra ${BASE} una vez en el navegador para aceptar su certificado.`);
  }
  const json = await respuesta.json().catch(() => ({}));
  if (!respuesta.ok || json.finalizado === false) {
    throw new JacobitusError(campo(json, ['mensaje', 'message', 'error']) || `Jacobitus respondió con el error ${respuesta.status}.`);
  }
  return json;
}

/** true si el servicio local responde (cualquier respuesta HTTP indica que está levantado). */
export async function verificarEstado() {
  try {
    await fetch(`${BASE}${RUTAS.estado}`, { method: 'GET' });
    return true;
  } catch {
    return false;
  }
}

/** Tokens y softokens conectados: [{ slot, nombre }]. */
export async function listarTokens() {
  const datos = datosDe(await llamar(RUTAS.tokens));
  const lista = campo(datos, ['tokens', 'connected_tokens', 'lista']) ?? (Array.isArray(datos) ? datos : []);
  return lista.map((token, indice) => ({
    slot: campo(token, ['slot', 'slotId', 'id']) ?? indice,
    nombre: [campo(token, ['name', 'nombre', 'label']), campo(token, ['model', 'modelo'])].filter(Boolean).join(' · ') || `Token ${indice + 1}`,
  }));
}

/** Certificados del token (requiere el PIN): [{ alias, numeroSerie, titular, emisor, desde, hasta }]. */
export async function leerCertificados(slot, pin) {
  const datos = datosDe(await llamar(RUTAS.datosToken, { metodo: 'POST', cuerpo: { slot, pin } }));
  const lista = campo(datos, ['data_token.data', 'data_token.certificates', 'certificados', 'certificates', 'data']) ?? [];
  return lista
    .filter((item) => !item.tipo || String(item.tipo).toUpperCase().includes('CERT'))
    .map((item) => ({
      alias: campo(item, ['alias', 'id', 'label']),
      numeroSerie: String(campo(item, ['serialNumber', 'numero_serie', 'numeroSerie', 'serial', 'nro_serie']) ?? ''),
      titular: campo(item, ['common_name', 'titular.nombre', 'titular.common_name', 'subject.CN', 'nombre']) ?? 'Titular desconocido',
      emisor: campo(item, ['emisor.common_name', 'emisor.nombre', 'issuer.CN', 'emisor', 'issuer']) ?? 'Emisor desconocido',
      desde: String(campo(item, ['validez.desde', 'validity.notBefore', 'fecha_emision', 'desde']) ?? '').slice(0, 10),
      hasta: String(campo(item, ['validez.hasta', 'validity.notAfter', 'fecha_expiracion', 'hasta']) ?? '').slice(0, 10),
    }));
}

/** Firma un PDF (Base64) y devuelve el PDF co-firmado en Base64. */
export async function firmarPdf(slot, pin, alias, pdfBase64) {
  const datos = datosDe(await llamar(RUTAS.firmarPdf, { metodo: 'POST', cuerpo: { slot, pin, alias, pdf: pdfBase64 } }));
  const firmado = campo(datos, ['pdf_firmado', 'pdfFirmado', 'documento_firmado', 'pdf', 'file']);
  if (!firmado) throw new JacobitusError('Jacobitus no devolvió el PDF firmado.');
  return String(firmado).replace(/^data:application\/pdf;base64,/, '');
}

/**
 * Firma varios PDF con un solo ingreso del PIN (uno tras otro contra Jacobitus).
 * @param {Array<{id: number, etiqueta: string, pdf_base64: string}>} documentos
 * @param {(hechos: number, total: number) => void} [alAvanzar]
 */
export async function firmarLote(slot, pin, alias, documentos, alAvanzar) {
  const firmados = [];
  for (const [indice, documento] of documentos.entries()) {
    try {
      firmados.push({ id: documento.id, pdf_base64: await firmarPdf(slot, pin, alias, documento.pdf_base64) });
    } catch (error) {
      throw new JacobitusError(`No se pudo firmar «${documento.etiqueta}»: ${error.message}`);
    }
    alAvanzar?.(indice + 1, documentos.length);
  }
  return firmados;
}
