import api from './axios';

// CU7: Monitoreo SLA de 72 horas hábiles
export const obtenerMonitorSla = (incluirAtendidas = false) => api.get('/sla/monitor', { params: { incluir_atendidas: incluirAtendidas ? 1 : 0 } });
export const obtenerDesgloseFases = (tramiteId) => api.get(`/sla/tramites/${tramiteId}/fases`);
export const atenderAlerta = (alertaId) => api.patch(`/sla/alertas/${alertaId}/atender`);
export const guardarConfiguracionSla = (payload) => api.put('/sla/configuracion', payload);
export const eliminarConfiguracionSla = (tipo) => api.delete(`/sla/configuracion/${tipo}`);
