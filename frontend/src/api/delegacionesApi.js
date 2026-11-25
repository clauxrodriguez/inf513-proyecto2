import api from './axios';

export const obtenerDelegaciones = () => api.get('/delegaciones');
export const crearDelegacion = (payload) => api.post('/delegaciones', payload);
export const revocarDelegacion = (id, motivo_revocacion) => api.patch(`/delegaciones/${id}/revocar`, { motivo_revocacion });
