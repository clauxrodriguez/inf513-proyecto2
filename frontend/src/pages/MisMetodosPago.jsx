import { useCallback, useEffect, useState } from 'react';
import { CreditCard, Plus, Star, Trash2 } from 'lucide-react';
import { eliminarTarjeta, guardarTarjeta, marcarPredeterminada, obtenerMetodosPago, prepararTarjeta } from '../api/pagosApi';
import CampoTarjeta from '../components/pagos/CampoTarjeta';
import { mensajeErrorApi } from '../components/tramites/calculosTramite';
import Alerta from '../components/ui/Alerta';
import { stripeConfigurado } from '../services/stripeService';

/** Billetera del estudiante: tarjetas tokenizadas por Stripe (modo de prueba: 4242 4242 4242 4242). */
export default function MisMetodosPago() {
  const [metodos, setMetodos] = useState([]);
  const [agregando, setAgregando] = useState(false);
  const [stripe, setStripe] = useState(null);
  const [completo, setCompleto] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [mensaje, setMensaje] = useState('');
  const [error, setError] = useState('');

  const cargar = useCallback(async () => {
    try {
      setMetodos((await obtenerMetodosPago()).data);
    } catch (err) {
      setError(mensajeErrorApi(err, 'No se pudieron cargar sus tarjetas.'));
    }
  }, []);

  useEffect(() => { cargar(); }, [cargar]);

  const ejecutar = async (accion) => {
    setError('');
    setMensaje('');
    setOcupado(true);
    try {
      await accion();
      await cargar();
    } catch (err) {
      setError(err?.response ? mensajeErrorApi(err, 'No se pudo completar la operación.') : err.message);
    } finally {
      setOcupado(false);
    }
  };

  const guardar = (event) => {
    event.preventDefault();
    if (!stripe || !completo) { setError('Complete los datos de la tarjeta.'); return; }
    ejecutar(async () => {
      const { client_secret: secreto } = (await prepararTarjeta()).data;
      const resultado = await stripe.stripe.confirmCardSetup(secreto, { payment_method: { card: stripe.tarjeta } });
      if (resultado.error) throw new Error(resultado.error.message);
      setMensaje((await guardarTarjeta(resultado.setupIntent.payment_method)).data.message);
      setAgregando(false);
    });
  };

  const quitar = (metodo) => {
    if (!window.confirm(`¿Eliminar la tarjeta ${metodo.marca} terminada en ${metodo.ultimos_cuatro}?`)) return;
    ejecutar(async () => setMensaje((await eliminarTarjeta(metodo.id)).data.message));
  };

  return (
    <div className="max-w-3xl space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-slate-800">Mis métodos de pago</h1>
          <p className="text-sm text-slate-500">Tarjetas para pagar al contado el arancel de sus trámites.</p>
        </div>
        {!agregando && stripeConfigurado() && (
          <button type="button" onClick={() => { setAgregando(true); setError(''); setMensaje(''); }}
            className="inline-flex items-center gap-2 rounded-lg bg-blue-900 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">
            <Plus className="h-4 w-4" aria-hidden="true" />Agregar tarjeta
          </button>
        )}
      </header>
      {!stripeConfigurado() && <Alerta mensaje="Los pagos en línea no están configurados en este entorno (VITE_STRIPE_KEY)." />}
      <Alerta tipo="exito" mensaje={mensaje} />
      <Alerta mensaje={error} />

      {agregando && (
        <form onSubmit={guardar} noValidate className="space-y-3 rounded-xl border border-slate-200 bg-white p-5 text-sm">
          <h2 className="font-semibold text-slate-800">Nueva tarjeta</h2>
          <CampoTarjeta onListo={setStripe} onCambio={({ completo: listo }) => setCompleto(listo)} />
          <p className="text-xs text-slate-500">Modo de prueba: use 4242 4242 4242 4242, una fecha futura y cualquier CVC.</p>
          <div className="flex justify-end gap-2">
            <button type="button" onClick={() => setAgregando(false)} disabled={ocupado} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-50">Cancelar</button>
            <button type="submit" disabled={ocupado || !completo} className="rounded-lg bg-blue-900 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-50">
              {ocupado ? 'Guardando...' : 'Guardar tarjeta'}
            </button>
          </div>
        </form>
      )}

      <ul className="space-y-2">
        {metodos.map((metodo) => (
          <li key={metodo.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm">
            <span className="flex items-center gap-3">
              <CreditCard className="h-6 w-6 text-blue-900" aria-hidden="true" />
              <span>
                <span className="block font-semibold text-slate-800">{metodo.marca} •••• {metodo.ultimos_cuatro}</span>
                <span className="block text-xs text-slate-500">Vence {String(metodo.mes_expiracion).padStart(2, '0')}/{metodo.anio_expiracion}</span>
              </span>
              {metodo.predeterminado && <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Predeterminada</span>}
            </span>
            <span className="flex gap-2">
              {!metodo.predeterminado && (
                <button type="button" disabled={ocupado} onClick={() => ejecutar(async () => setMensaje((await marcarPredeterminada(metodo.id)).data.message))}
                  className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50">
                  <Star className="h-3.5 w-3.5" aria-hidden="true" />Predeterminar
                </button>
              )}
              <button type="button" disabled={ocupado} onClick={() => quitar(metodo)} aria-label={`Eliminar ${metodo.marca} terminada en ${metodo.ultimos_cuatro}`}
                className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-xs text-red-600 hover:bg-red-50">
                <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />Eliminar
              </button>
            </span>
          </li>
        ))}
        {!metodos.length && <li className="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">Aún no registró tarjetas.</li>}
      </ul>
    </div>
  );
}
