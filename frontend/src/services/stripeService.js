/**
 * Carga Stripe.js desde js.stripe.com (exigido por Stripe para cumplir PCI) una sola vez y devuelve
 * la instancia configurada con la llave pública. Los datos de la tarjeta los captura el campo de Stripe:
 * nunca pasan por el código de la aplicación ni por el backend.
 */
const LLAVE_PUBLICA = import.meta.env.VITE_STRIPE_KEY;
let instancia = null;

export const stripeConfigurado = () => Boolean(LLAVE_PUBLICA);

export function cargarStripe() {
  if (!LLAVE_PUBLICA) return Promise.reject(new Error('Los pagos en línea no están configurados (VITE_STRIPE_KEY).'));
  if (instancia) return instancia;

  instancia = new Promise((resolver, rechazar) => {
    const crear = () => resolver(window.Stripe(LLAVE_PUBLICA, { locale: 'es' }));
    if (window.Stripe) { crear(); return; }
    const script = document.createElement('script');
    script.src = 'https://js.stripe.com/v3/';
    script.async = true;
    script.onload = crear;
    script.onerror = () => { instancia = null; rechazar(new Error('No se pudo cargar la pasarela de pago. Verifique su conexión a internet.')); };
    document.head.appendChild(script);
  });

  return instancia;
}

/** Estilo del campo de tarjeta acorde al tema y modo actuales (lee los colores de la página). */
export function estiloCampoTarjeta() {
  const estilos = getComputedStyle(document.body);
  return {
    base: { color: estilos.color, fontFamily: estilos.fontFamily, fontSize: '16px', '::placeholder': { color: '#94a3b8' } },
    invalid: { color: '#dc2626' },
  };
}
