import { useEffect, useRef, useState } from 'react';
import { cargarStripe, estiloCampoTarjeta } from '../../services/stripeService';

/**
 * Campo de tarjeta de Stripe (Card Element). onListo recibe { stripe, tarjeta } cuando está montado;
 * onCambio informa si los datos están completos y el mensaje de error de Stripe (en español).
 */
export default function CampoTarjeta({ onListo, onCambio }) {
  const contenedor = useRef(null);
  const [error, setError] = useState('');
  const callbacks = useRef({ onListo, onCambio });

  useEffect(() => {
    callbacks.current = { onListo, onCambio };
  }, [onListo, onCambio]);

  useEffect(() => {
    let tarjeta;
    let vigente = true;
    cargarStripe()
      .then((stripe) => {
        if (!vigente || !contenedor.current) return;
        tarjeta = stripe.elements({ locale: 'es' }).create('card', { hidePostalCode: true, style: estiloCampoTarjeta() });
        tarjeta.mount(contenedor.current);
        tarjeta.on('change', (evento) => {
          setError(evento.error?.message || '');
          callbacks.current.onCambio?.({ completo: evento.complete, error: evento.error?.message || '' });
        });
        callbacks.current.onListo?.({ stripe, tarjeta });
      })
      .catch((err) => { if (vigente) setError(err.message); });
    return () => {
      vigente = false;
      tarjeta?.destroy();
    };
  }, []);

  return (
    <div>
      <div ref={contenedor} className="rounded-lg border border-slate-300 bg-white px-3 py-3" aria-label="Datos de la tarjeta" />
      {error && <p role="alert" className="mt-1 text-xs text-red-700">{error}</p>}
      <p className="mt-1 text-xs text-slate-500">Los datos de la tarjeta los procesa Stripe de forma segura; el sistema solo guarda la marca y los últimos 4 dígitos.</p>
    </div>
  );
}
