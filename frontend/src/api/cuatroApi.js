import api from './axios';

export const obtenerGuiaProcesal = () => api.get('/tramites/guia');
export const obtenerDetalleTramite = (id) => api.get(`/tramites/${id}`);
export const registrarObservacion = (id, payload) => api.post(`/tramites/${id}/observaciones`, payload);
export const subsanarObservacion = (id, formData) => api.post(`/tramites/observaciones/${id}/subsanar`, formData, {
  headers: { 'Content-Type': 'multipart/form-data' },
});
export const actualizarRequisito = (tramiteId, requisitoId, cumplido) => (
  api.patch(`/tramites/${tramiteId}/requisitos/${requisitoId}`, { cumplido })
);
export const enviarADespacho = (id) => api.patch(`/tramites/${id}/enviar-despacho`);
export const enrutarPosteriorFirma = (id) => api.patch(`/tramites/${id}/enrutar-posterior-firma`);
export const obtenerBitacora = (params = {}) => api.get('/bitacora', { params });
export const iniciarRevision = (id) => api.patch(`/tramites/${id}/iniciar-revision`);
export const obtenerArchivoDocumento = (documentoId) => api.get(`/tramites/documentos/${documentoId}/archivo`, { responseType: 'blob' });
export const obtenerArchivoExpediente = (expedienteId) => api.get(`/tramites/expedientes/${expedienteId}/archivo`, { responseType: 'blob' });
