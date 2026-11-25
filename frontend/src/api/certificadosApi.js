import api from './axios';

// CU6: Certificado oficial con QR (Recepción emite; Recepción y autoridades consultan y anulan)
export const obtenerCertificados = () => api.get('/certificados');
export const emitirCertificado = (tramiteId) => api.post(`/certificados/tramites/${tramiteId}/emitir`);
export const obtenerCertificadoPdf = (id) => api.get(`/certificados/${id}/pdf`, { responseType: 'blob' });
export const anularCertificado = (id, motivo_anulacion) => api.post(`/certificados/${id}/anular`, { motivo_anulacion });

/** URL pública del validador (la misma que codifica el QR del certificado). */
export const urlValidador = (codigo, hash) => {
  const base = `${window.location.origin}${import.meta.env.BASE_URL}`.replace(/\/$/, '');
  const params = new URLSearchParams({ codigo, ...(hash ? { hash } : {}) });
  return `${base}/validar-certificado?${params}`;
};
