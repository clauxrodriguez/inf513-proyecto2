import api from './axios';

export const obtenerUsuarios = () => api.get('/usuarios');
export const crearUsuario = (payload) => api.post('/usuarios', payload);
export const actualizarUsuario = (id, payload) => api.put(`/usuarios/${id}`, payload);
export const cambiarEstadoUsuario = (id) => api.patch(`/usuarios/${id}/estado`);
export const actualizarPerfil = (payload) => api.put('/perfil', payload);
