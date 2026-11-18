import api from './axios';

// RF-19: notificaciones del usuario autenticado
export const obtenerNotificaciones = () => api.get('/notificaciones');
export const marcarNotificacionLeida = (id) => api.patch(`/notificaciones/${id}/leida`);
export const marcarTodasLeidas = () => api.patch('/notificaciones/leidas');
