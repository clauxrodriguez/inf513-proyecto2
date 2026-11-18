import api from './axios';

export const obtenerCatalogos = () => api.get('/tramites/catalogos');
export const listarTramites = (params = {}) => api.get('/tramites', { params });
export const crearTramite = (formData) => api.post('/tramites', formData, {
  headers: { 'Content-Type': 'multipart/form-data' },
});
export const validarDocumento = (id, payload) => (
  api.patch(`/tramites/documentos/${id}/validar`, payload)
);
