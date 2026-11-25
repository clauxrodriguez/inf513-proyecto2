import api from './axios';

export const obtenerCatalogos = () => api.get('/tramites/catalogos');
export const listarTramites = (params = {}) => api.get('/tramites', { params });
export const crearTramite = (formData) => api.post('/tramites', formData, {
  headers: { 'Content-Type': 'multipart/form-data' },
});

// Ventanilla del Vicerrectorado
export const verificarFirmasDocumento = (documentoId) => api.get(`/tramites/documentos/${documentoId}/verificacion`);
export const registrarComprobante = (tramiteId, payload) => api.post(`/tramites/${tramiteId}/comprobantes`, payload);
export const eliminarComprobante = (comprobanteId) => api.delete(`/tramites/comprobantes/${comprobanteId}`);
export const compilarExpediente = (tramiteId) => api.post(`/tramites/${tramiteId}/compilar`);
