import api from './axios';

// CU6: endpoints públicos (sin sesión)
export const consultarSeguimiento = (registro, ci) => api.post('/publico/seguimiento', { registro, ci });
export const verificarCertificado = (codigo, hash) => api.get('/publico/certificados/verificar', { params: { codigo, hash: hash || undefined } });
/** Valida las firmas del PDF (Validador Web de AGETIC) y su correspondencia con un certificado oficial. */
export const validarPdf = (archivo, codigo = null) => {
  const datos = new FormData();
  datos.append('pdf', archivo);
  if (codigo) datos.append('codigo', codigo);
  return api.post('/certificados/validar-pdf', datos, { headers: { 'Content-Type': 'multipart/form-data' } });
};
export const descargarCertificadoPublico = (codigo) => api.get(`/publico/certificados/${codigo}/pdf`, { responseType: 'blob' });

