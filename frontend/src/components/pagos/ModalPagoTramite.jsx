import { useEffect, useState } from 'react';
import { CreditCard, Lock } from 'lucide-react';
import { confirmarPago, iniciarPago, obtenerMetodosPago } from '../../api/pagosApi';
import { cargarStripe } from '../../services/stripeService';
import { mensajeErrorApi, moneda } from '../tramites/calculosTramite';
import Alerta from '../ui/Alerta';
import Modal from '../ui/Modal';
import CampoTarjeta from './CampoTarjeta';

const NUEVA = 'nueva';

/**
 * Pago al contado del saldo del arancel: con una tarjeta guardada o una nueva. El cobro se confirma en el
 * navegador con Stripe.js (admite 3-D Secure) y luego el backend consulta el resultado real en Stripe.
 */
export default function ModalPagoTramite({ tramite, onCerrar, onPagado }) {
  const saldo = Math.max(0, Number(tramite.importe_esperado) - Number(tramite.importe_pagado));
  const [metodos, setMetodos] = useState(null);
  const [elegido, setElegido] = useState(NUEVA);
  const [campo, setCampo] = useState(null);
  const [completo, setCompleto] = useState(false);
  const [pagando, setPagando] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let vigente = true;
    obtenerMetodosPago()
      .then((res) => {
        if (!vigente) return;
        setMetodos(res.data);
        const predeterminada = res.data.find((m) => m.predeterminado) || res.data[0];
        if (predeterminada) setElegido(String(predeterminada.id));
      })
      .catch(() => { if (vigente) setMetodos([]); });
    return () => { vigente = false; };
  }, []);

  const pagar = async (event) => {
    event.preventDefault();
    if (elegido === NUEVA && (!campo || !completo)) { setError('Complete los datos de la tarjeta.'); return; }
    setError('');
    setPagando(true);
    try {
      const stripe = elegido === NUEVA ? campo.stripe : await cargarStripe();
      const pago = (await iniciarPago(tramite.id, elegido === NUEVA ? null : Number(elegido))).data;
      const resultado = await stripe.confirmCardPayment(pago.client_secret, {
        payment_method: elegido === NUEVA ? { card: campo.tarjeta } : pago.metodo_stripe,
      });
      // Aun si Stripe.js informa un error, el backend registra el estado real (fallido) de la transacción
      const confirmado = (await confirmarPago(pago.transaccion_id)).data;
      if (resultado.error && confirmado.estado !== 'EXITOSO') throw new Error(resultado.error.message);
      if (confirmado.estado !== 'EXITOSO') throw new Error(confirmado.message);
      onPagado(confirmado.message);
    } catch (err) {
      setError(err?.response ? mensajeErrorApi(err, 'No se pudo procesar el pago.') : err.message);
    } finally {
      setPagando(false);
    }
  };

  return (
    <Modal titulo={`Pagar arancel · ${tramite.codigo}`}>
      <form onSubmit={pagar} noValidate className="space-y-4 text-sm">
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs text-slate-500">Total a pagar al contado</p>
          <p className="text-2xl font-bold text-slate-800">{moneda(saldo)}</p>
          <p className="text-xs text-slate-500">Arancel {moneda(tramite.importe_esperado)} · ya pagado {moneda(tramite.importe_pagado)}</p>
        </div>

        {metodos === null ? <p className="text-slate-500">Cargando sus tarjetas...</p> : (
          <fieldset className="space-y-2">
            <legend className="mb-1 font-medium text-slate-700">Método de pago</legend>
            {metodos.map((m) => (
              <label key={m.id} className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 ${elegido === String(m.id) ? 'border-blue-800 bg-blue-50' : 'border-slate-300'}`}>
                <input type="radio" name="metodo" value={m.id} checked={elegido === String(m.id)} onChange={(e) => setElegido(e.target.value)} />
                <CreditCard className="h-4 w-4 text-slate-500" aria-hidden="true" />{m.marca} •••• {m.ultimos_cuatro}
                <span className="text-xs text-slate-500">vence {String(m.mes_expiracion).padStart(2, '0')}/{m.anio_expiracion}</span>
              </label>
            ))}
            <label className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 ${elegido === NUEVA ? 'border-blue-800 bg-blue-50' : 'border-slate-300'}`}>
              <input type="radio" name="metodo" value={NUEVA} checked={elegido === NUEVA} onChange={(e) => setElegido(e.target.value)} />Otra tarjeta
            </label>
            {elegido === NUEVA && <CampoTarjeta onListo={setCampo} onCambio={({ completo: listo }) => setCompleto(listo)} />}
          </fieldset>
        )}

        <Alerta mensaje={error} />
        <div className="flex justify-end gap-2">
          <button type="button" onClick={onCerrar} disabled={pagando} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-700 hover:bg-slate-50">Cancelar</button>
          <button type="submit" disabled={pagando || metodos === null || saldo <= 0}
            className="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 font-medium text-white hover:bg-emerald-800 disabled:opacity-50">
            <Lock className="h-4 w-4" aria-hidden="true" />{pagando ? 'Procesando pago...' : `Pagar ${moneda(saldo)} al contado`}
          </button>
        </div>
      </form>
    </Modal>
  );
}
