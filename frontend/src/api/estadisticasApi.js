import api from './axios';

export const obtenerResumenDashboard = () => api.get('/dashboard');
export const obtenerReporte = (params = {}) => api.get('/reportes', { params });
export const buscarGlobal = (q, config = {}) => api.get('/buscar', { params: { q }, ...config });
