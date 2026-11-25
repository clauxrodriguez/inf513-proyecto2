import api from './axios';

// Pagos en línea al contado (Stripe)
export const obtenerMetodosPago = () => api.get('/metodos-pago');
export const prepararTarjeta = () => api.post('/metodos-pago/setup-intent');
export const guardarTarjeta = (paymentMethodId) => api.post('/metodos-pago', { payment_method_id: paymentMethodId });
export const marcarPredeterminada = (id) => api.patch(`/metodos-pago/${id}/predeterminado`);
export const eliminarTarjeta = (id) => api.delete(`/metodos-pago/${id}`);
export const iniciarPago = (tramiteId, metodoPagoId = null) => api.post(`/tramites/${tramiteId}/pagar`, { metodo_pago_id: metodoPagoId });
export const confirmarPago = (transaccionId) => api.post(`/pagos/${transaccionId}/confirmar`);
export const obtenerHistorialPagos = () => api.get('/pagos');
