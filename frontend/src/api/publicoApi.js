import api from './axios';

// CU6: endpoints públicos (sin sesión)
export const consultarSeguimiento = (registro, ci) => api.post('/publico/seguimiento', { registro, ci });
export const verificarCertificado = (codigo, hash) => api.get('/publico/certificados/verificar', { params: { codigo, hash: hash || undefined } });
export const verificarArchivoPdf = (archivo) => {
  const datos = new FormData();
  datos.append('pdf', archivo);
  return api.post('/publico/certificados/verificar-archivo', datos, { headers: { 'Content-Type': 'multipart/form-data' } });
};
export const descargarCertificadoPublico = (codigo) => api.get(`/publico/certificados/${codigo}/pdf`, { responseType: 'blob' });

