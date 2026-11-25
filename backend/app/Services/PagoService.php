<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\AuditoriaRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\PagoRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Pagos en línea al contado con Stripe: billetera de tarjetas del estudiante y pago del arancel de la legalización.
 * Un pago exitoso se registra como recibo en comprobante_caja (el trigger actualiza importe_pagado) y cumple
 * el requisito COMPROBANTE, de modo que las reglas existentes del flujo no cambian.
 */
class PagoService
{
    /** Estados del trámite en los que el estudiante puede pagar el arancel. */
    private const ESTADOS_PAGABLES = ['SOLICITADO_ORIGEN', 'EN_FIRMA_ORIGEN', 'INGRESADO', 'EN_REVISION', 'OBSERVADO'];

    /** @var array<string, string> estado del PaymentIntent de Stripe => estado de transaccion_pago */
    private const ESTADOS_STRIPE = [
        'succeeded' => 'EXITOSO',
        'requires_action' => 'REQUIERE_ACCION',
        'requires_confirmation' => 'PENDIENTE',
        'processing' => 'PENDIENTE',
        'requires_payment_method' => 'FALLIDO',
        'canceled' => 'CANCELADO',
    ];

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private PagoRepository $pagos,
        private TramiteRepository $tramites,
        private NotificacionRepository $notificaciones,
        private AuditoriaRepository $auditoria,
        private AuditoriaService $contexto,
        private StripeService $stripe,
    ) {}

    // ------------------------------------------------------------------ billetera

    public function metodos(User $usuario): Collection
    {
        return $this->pagos->metodosDe($usuario->id);
    }

    /**
     * client_secret del SetupIntent con el que Stripe.js guarda una tarjeta nueva.
     *
     * @return array{client_secret: string}
     */
    public function prepararTarjeta(User $usuario): array
    {
        return ['client_secret' => $this->stripe->crearSetupIntent($this->clienteStripe($usuario))['client_secret']];
    }

    /**
     * Registra la tarjeta tokenizada por Stripe.js; se verifica en Stripe que pertenezca al cliente del usuario.
     */
    public function guardarTarjeta(User $usuario, string $metodoPagoId): int
    {
        $metodo = $this->stripe->obtenerMetodoPago($metodoPagoId);
        if (($metodo['customer'] ?? null) !== $this->clienteStripe($usuario) || ($metodo['type'] ?? null) !== 'card') {
            throw new ReglaNegocioException('La tarjeta no pertenece a su cuenta de pagos.');
        }

        return $this->pagos->guardarMetodo([
            'usuario_id' => $usuario->id,
            'stripe_payment_method_id' => $metodoPagoId,
            'marca' => strtoupper((string) ($metodo['card']['brand'] ?? 'TARJETA')),
            'ultimos_cuatro' => (string) $metodo['card']['last4'],
            'mes_expiracion' => (int) $metodo['card']['exp_month'],
            'anio_expiracion' => (int) $metodo['card']['exp_year'],
        ]);
    }

    public function predeterminar(User $usuario, int $metodoId): void
    {
        $this->obtenerMetodo($usuario, $metodoId);
        $this->transaccion->ejecutar(fn () => $this->pagos->marcarPredeterminado($metodoId, $usuario->id));
    }

    public function eliminarTarjeta(User $usuario, int $metodoId): void
    {
        $metodo = $this->obtenerMetodo($usuario, $metodoId);
        $this->stripe->separarMetodoPago($metodo->stripe_payment_method_id);
        $this->pagos->desactivarMetodo($metodoId, $usuario->id);
    }

    // ------------------------------------------------------------------ pago al contado

    /**
     * Crea el PaymentIntent por el saldo pendiente del arancel; el navegador lo confirma con Stripe.js.
     *
     * @return array{transaccion_id: int, client_secret: string, monto: float, moneda: string, metodo_stripe: ?string}
     */
    public function iniciarPago(User $usuario, int $tramiteId, ?int $metodoId): array
    {
        $tramite = $this->tramitePagable($usuario, $tramiteId);
        $saldo = round((float) $tramite->importe_esperado - (float) $tramite->importe_pagado, 2);
        $metodo = $metodoId ? $this->obtenerMetodo($usuario, $metodoId) : null;
        $moneda = config('services.stripe.moneda');

        $intent = $this->stripe->crearPaymentIntent(array_filter([
            'amount' => $this->stripe->unidadMinima($saldo),
            'currency' => $moneda,
            'customer' => $this->clienteStripe($usuario),
            'payment_method' => $metodo?->stripe_payment_method_id,
            'payment_method_types' => ['card'],
            'description' => "Arancel de legalización {$tramite->codigo} - UAGRM",
            'receipt_email' => $usuario->email,
            'metadata' => ['tramite_id' => $tramite->id, 'tramite_codigo' => $tramite->codigo, 'usuario_id' => $usuario->id],
        ]), 'pago-'.$tramite->id.'-'.Str::uuid());

        $transaccionId = $this->pagos->crearTransaccion([
            'tramite_id' => $tramite->id,
            'usuario_id' => $usuario->id,
            'metodo_pago_id' => $metodo?->id,
            'stripe_payment_intent_id' => $intent['id'],
            'monto' => $saldo,
            'moneda' => strtoupper($moneda),
        ]);

        return [
            'transaccion_id' => $transaccionId,
            'client_secret' => $intent['client_secret'],
            'monto' => $saldo,
            'moneda' => strtoupper($moneda),
            'metodo_stripe' => $metodo?->stripe_payment_method_id,
        ];
    }

    /**
     * Tras confirmar en el navegador, el backend consulta el resultado real en Stripe (no confía en el cliente).
     *
     * @return array{estado: string, transaccion: object}
     */
    public function confirmarPago(User $usuario, int $transaccionId): array
    {
        $transaccion = $this->pagos->buscarTransaccion($transaccionId);
        if (! $transaccion || (int) $transaccion->usuario_id !== $usuario->id) {
            throw ReglaNegocioException::noEncontrado('La transacción no existe.');
        }

        $estado = $this->registrarResultado($this->stripe->obtenerPaymentIntent($transaccion->stripe_payment_intent_id));

        return ['estado' => $estado, 'transaccion' => $this->pagos->buscarTransaccion($transaccionId)];
    }

    /**
     * Webhook firmado de Stripe: respaldo por si el navegador se cerró antes de confirmar.
     */
    public function procesarWebhook(string $cuerpo, ?string $firma): void
    {
        $evento = $this->stripe->eventoDeWebhook($cuerpo, $firma);
        if (! str_starts_with((string) ($evento['type'] ?? ''), 'payment_intent.')) {
            return;
        }

        $intentId = $evento['data']['object']['id'] ?? null;
        if ($intentId && $this->pagos->existeIntent($intentId)) {
            $this->registrarResultado($this->stripe->obtenerPaymentIntent($intentId));
        }
    }

    public function historial(User $usuario): Collection
    {
        return $this->pagos->historial($usuario->rol === 'ESTUDIANTE' ? $usuario->id : null);
    }

    /**
     * Registra el estado del PaymentIntent una sola vez (bloqueo de fila). Si el pago fue exitoso y el importe
     * cobrado coincide, crea el recibo en comprobante_caja, cumple el requisito COMPROBANTE, deja constancia
     * en la bitácora y notifica al estudiante y a la Ventanilla.
     *
     * @param  array<string, mixed>  $intent
     */
    private function registrarResultado(array $intent): string
    {
        return $this->transaccion->ejecutar(function () use ($intent): string {
            $transaccion = $this->pagos->bloquearPorIntent($intent['id'])
                ?? throw ReglaNegocioException::noEncontrado('El pago no corresponde a ninguna transacción del sistema.');
            if ($transaccion->estado === 'EXITOSO') {
                return 'EXITOSO';
            }

            $this->contexto->establecerContexto((int) $transaccion->usuario_id);
            $estado = self::ESTADOS_STRIPE[$intent['status'] ?? ''] ?? 'PENDIENTE';

            if ($estado !== 'EXITOSO') {
                $this->pagos->actualizarTransaccion($transaccion->id, [
                    'estado' => $estado,
                    'mensaje_error' => mb_substr((string) ($intent['last_payment_error']['message'] ?? ''), 0, 255) ?: null,
                ]);

                return $estado;
            }

            $esperado = $this->stripe->unidadMinima((float) $transaccion->monto);
            if ((int) ($intent['amount_received'] ?? 0) !== $esperado || strtoupper((string) $intent['currency']) !== $transaccion->moneda) {
                throw new ReglaNegocioException('El importe cobrado por la pasarela no coincide con el de la transacción.');
            }

            $comprobanteId = $this->tramites->crearComprobante([
                'tramite_id' => $transaccion->tramite_id,
                'codigo_recibo' => mb_substr($intent['id'], 0, 30),
                'concepto' => 'LEGALIZACION',
                'importe' => $transaccion->monto,
                'fecha_pago' => now()->toDateString(),
                'registrado_por' => $transaccion->usuario_id,
            ]);
            $this->pagos->actualizarTransaccion($transaccion->id, [
                'estado' => 'EXITOSO',
                'mensaje_error' => null,
                'comprobante_caja_id' => $comprobanteId,
                'recibo_url' => $intent['latest_charge']['receipt_url'] ?? null,
                'fecha_pago' => now(),
            ]);
            $this->tramites->marcarRequisitoCumplido($transaccion->tramite_id, 'COMPROBANTE', (int) $transaccion->usuario_id);

            $tramite = $this->tramites->buscar($transaccion->tramite_id);
            $this->auditoria->registrarEvento($tramite->id, 'PAGO_EN_LINEA', 'transaccion_pago', $transaccion->id, [
                'monto' => (float) $transaccion->monto, 'moneda' => $transaccion->moneda, 'payment_intent' => $intent['id'],
            ]);
            $this->notificaciones->notificarUsuario($tramite->solicitante_id, $tramite->id, 'PAGO_EXITOSO',
                "Se registró su pago de Bs {$transaccion->monto} del trámite {$tramite->codigo}.");
            $this->notificaciones->notificarRol('RECEPCION', $tramite->id, 'PAGO_EXITOSO',
                "El trámite {$tramite->codigo} fue pagado en línea (Bs {$transaccion->monto}).");

            return 'EXITOSO';
        });
    }

    private function tramitePagable(User $usuario, int $tramiteId): object
    {
        $tramite = $this->tramites->buscar($tramiteId);
        if (! $tramite || (int) $tramite->solicitante_id !== $usuario->id) {
            throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        }
        if ($tramite->tipo !== 'LEGALIZACION') {
            throw new ReglaNegocioException('El pago en línea aplica al arancel de las legalizaciones.');
        }
        if (! in_array($tramite->estado, self::ESTADOS_PAGABLES, true)) {
            throw new ReglaNegocioException("El trámite {$tramite->codigo} ya no admite pagos (estado {$tramite->estado}).");
        }
        if ($this->pagos->tienePagoExitoso($tramiteId) || (float) $tramite->importe_pagado >= (float) $tramite->importe_esperado) {
            throw new ReglaNegocioException("El arancel del trámite {$tramite->codigo} ya está pagado.");
        }

        return $tramite;
    }

    private function obtenerMetodo(User $usuario, int $metodoId): object
    {
        return $this->pagos->buscarMetodo($metodoId, $usuario->id) ?? throw ReglaNegocioException::noEncontrado('La tarjeta no existe.');
    }

    /**
     * Cliente de Stripe del usuario (se crea la primera vez).
     */
    private function clienteStripe(User $usuario): string
    {
        if ($cliente = $this->pagos->clienteStripeDe($usuario->id)) {
            return $cliente;
        }

        $cliente = $this->stripe->crearCliente($usuario->email, trim("{$usuario->nombres} {$usuario->apellidos}"), $usuario->id)['id'];
        $this->pagos->asignarClienteStripe($usuario->id, $cliente);

        return $cliente;
    }
}
