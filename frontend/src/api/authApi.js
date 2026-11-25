import api from './axios';

export const iniciarSesion = (email, password, rol = null) => api.post('/login', { email, password, ...(rol ? { rol } : {}) });
export const obtenerSesion = () => api.get('/me');
export const obtenerMenu = () => api.get('/menu');
export const cambiarRolActivo = (rol) => api.put('/sesion/rol', { rol });
export const cerrarSesion = () => api.post('/logout');
export const registrarVisita = (ruta, tituloPagina) => api.post('/visitas', { ruta, titulo_pagina: tituloPagina });
