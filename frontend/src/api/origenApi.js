import api from './axios';

// Bandeja facultativa: emisión y firma digital en cadena en el origen
export const obtenerBandejaOrigen = () => api.get('/origen/bandeja');
export const emitirDocumento = (documentoId, archivo) => {
  const body = new FormData();
  body.append('archivo', archivo);
  return api.post(`/origen/documentos/${documentoId}/emitir`, body, { headers: { 'Content-Type': 'multipart/form-data' } });
};
export const firmarEnOrigen = (payload) => api.post('/origen/firmar', payload);
