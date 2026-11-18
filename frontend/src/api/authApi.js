import api from './axios';

export const iniciarSesion = (email, password) => api.post('/login', { email, password });
export const obtenerSesion = () => api.get('/me');
export const cerrarSesion = () => api.post('/logout');
export const registrarVisita = (ruta, tituloPagina) => api.post('/visitas', { ruta, titulo_pagina: tituloPagina });
