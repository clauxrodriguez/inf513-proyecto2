import api from './axios';

export const obtenerDelegaciones = () => api.get('/delegaciones');
export const crearDelegacion = (formData) => api.post('/delegaciones', formData, {
  headers: { 'Content-Type': 'multipart/form-data' },
});
export const revocarDelegacion = (id, motivo_revocacion) => (
  api.patch(`/delegaciones/${id}/revocar`, { motivo_revocacion })
);
export const crearCertificadoDigital = (payload) => api.post('/certificados-digitales', payload);
export const obtenerResolucion = (id) => api.get(`/delegaciones/${id}/resolucion`, { responseType: 'blob' });
