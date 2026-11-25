import api from './axios';

// Firma con Jacobitus: el backend entrega los PDF, verifica las co-firmas y las registra
export const prepararFirma = (etapa, ids) => api.post('/firmas/preparar', { etapa, ids });
export const confirmarLoteFirmado = (payload) => api.post('/firmas/confirmar-lote', payload);
export const registrarCertificadoToken = (payload) => api.post('/firmas/certificado', payload);
