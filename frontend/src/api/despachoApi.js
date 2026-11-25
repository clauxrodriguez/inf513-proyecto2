import api from './axios';

// CU5: Despachar, previsualizar legajos y firma digital consolidada o remota
export const obtenerBandejaDespacho = () => api.get('/despacho');
export const obtenerLegajoPdf = (tramiteId) => api.get(`/despacho/${tramiteId}/legajo`, { responseType: 'blob' });
export const verificarFirmas = (tramiteId) => api.get(`/despacho/${tramiteId}/verificacion`);
export const firmarLote = (payload) => api.post('/despacho/firmar', payload);

// Rectorado: firma institucional de los trámites al exterior
export const obtenerBandejaRectorado = () => api.get('/rectorado/bandeja');
export const firmarRectorado = (tramiteId, pin) => api.post(`/rectorado/${tramiteId}/firmar`, { pin });

export const mensajeError = (err, fallback) => err.response?.data?.errors
  ? Object.values(err.response.data.errors).flat().join(' ')
  : err.response?.data?.message || fallback;

/** Los errores de una petición con responseType 'blob' llegan como Blob: se leen como JSON. */
export const mensajeErrorBlob = async (err, fallback) => {
  const datos = err.response?.data;
  if (datos instanceof Blob) {
    try {
      return JSON.parse(await datos.text()).message || fallback;
    } catch {
      return fallback;
    }
  }
  return mensajeError(err, fallback);
};
