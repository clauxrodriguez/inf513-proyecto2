<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la API REST de Stripe (https://api.stripe.com/v1) con el cliente HTTP de Laravel.
 * Los datos de la tarjeta nunca pasan por el backend: Stripe.js los tokeniza en el navegador y
 * aquí solo se manejan identificadores (cus_, pm_, seti_, pi_).
 */
class StripeService
{
    private const API = 'https://api.stripe.com/v1';

    /** Tolerancia de la firma del webhook (segundos). */
    private const TOLERANCIA_WEBHOOK = 300;

    public function configurado(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * @return array<string, mixed>
     */
    public function crearCliente(string $email, string $nombre, int $usuarioId): array
    {
        return $this->enviar('post', '/customers', ['email' => $email, 'name' => $nombre, 'metadata' => ['usuario_id' => $usuarioId]]);
    }

    /**
     * SetupIntent para que Stripe.js guarde una tarjeta nueva del cliente.
     *
     * @return array<string, mixed>
     */
    public function crearSetupIntent(string $clienteId): array
    {
        return $this->enviar('post', '/setup_intents', ['customer' => $clienteId, 'payment_method_types' => ['card'], 'usage' => 'on_session']);
    }

    /**
     * @return array<string, mixed>
     */
    public function obtenerMetodoPago(string $metodoPagoId): array
    {
        return $this->enviar('get', "/payment_methods/{$metodoPagoId}");
    }

    public function separarMetodoPago(string $metodoPagoId): void
    {
        $this->enviar('post', "/payment_methods/{$metodoPagoId}/detach");
    }

    /**
     * PaymentIntent del pago al contado; se confirma en el navegador (permite 3-D Secure).
     *
     * @param  array<string, mixed>  $parametros
     * @return array<string, mixed>
     */
    public function crearPaymentIntent(array $parametros, string $claveIdempotencia): array
    {
        return $this->enviar('post', '/payment_intents', $parametros, $claveIdempotencia);
    }

    /**
     * PaymentIntent con su último cargo (para el recibo).
     *
     * @return array<string, mixed>
     */
    public function obtenerPaymentIntent(string $paymentIntentId): array
    {
        return $this->enviar('get', "/payment_intents/{$paymentIntentId}", ['expand' => ['latest_charge']]);
    }

    /**
     * Verifica la firma del webhook (cabecera Stripe-Signature: t=...,v1=...) y devuelve el evento.
     *
     * @return array<string, mixed>
     */
    public function eventoDeWebhook(string $cuerpo, ?string $firma): array
    {
        $secreto = config('services.stripe.webhook_secret');
        if (! $secreto || ! $firma) {
            throw ReglaNegocioException::prohibido('Webhook de Stripe sin firma o sin secreto configurado.');
        }

        $partes = collect(explode(',', $firma))->mapWithKeys(function (string $parte): array {
            [$clave, $valor] = array_pad(explode('=', $parte, 2), 2, '');

            return [$clave => $valor];
        });
        $momento = (int) $partes->get('t');
        $esperada = hash_hmac('sha256', "{$momento}.{$cuerpo}", $secreto);

        $valida = collect(explode(',', $firma))
            ->filter(fn (string $parte) => str_starts_with($parte, 'v1='))
            ->contains(fn (string $parte) => hash_equals($esperada, substr($parte, 3)));

        if (! $valida || abs(time() - $momento) > self::TOLERANCIA_WEBHOOK) {
            throw ReglaNegocioException::prohibido('La firma del webhook de Stripe no es válida.');
        }

        return json_decode($cuerpo, true) ?? [];
    }

    /**
     * Importe en la unidad mínima de la moneda de cobro (centavos), convertido desde bolivianos.
     */
    public function unidadMinima(float $montoBolivianos): int
    {
        return (int) round($montoBolivianos * config('services.stripe.tipo_cambio') * 100);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function enviar(string $metodo, string $ruta, array $datos = [], ?string $claveIdempotencia = null): array
    {
        if (! $this->configurado()) {
            throw ReglaNegocioException::servicioNoDisponible('Los pagos en línea no están configurados (STRIPE_SECRET).');
        }

        try {
            $respuesta = $this->cliente($claveIdempotencia)->{$metodo}(self::API.$ruta, $datos);
        } catch (ConnectionException) {
            throw ReglaNegocioException::servicioNoDisponible('No se pudo conectar con Stripe. Verifique la conexión a internet del servidor.');
        }

        return $this->procesar($respuesta);
    }

    private function cliente(?string $claveIdempotencia): PendingRequest
    {
        return Http::withToken(config('services.stripe.secret'))
            ->asForm()
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(config('services.stripe.timeout'))
            ->when($claveIdempotencia, fn (PendingRequest $cliente) => $cliente->withHeaders(['Idempotency-Key' => $claveIdempotencia]));
    }

    /**
     * @return array<string, mixed>
     */
    private function procesar(Response $respuesta): array
    {
        if ($respuesta->failed()) {
            $error = $respuesta->json('error') ?? [];
            $mensaje = match ($error['code'] ?? null) {
                'card_declined' => 'La tarjeta fue rechazada por el banco emisor.',
                'expired_card' => 'La tarjeta está vencida.',
                'incorrect_cvc' => 'El código de seguridad (CVC) es incorrecto.',
                'insufficient_funds' => 'La tarjeta no tiene fondos suficientes.',
                'processing_error' => 'Ocurrió un error al procesar la tarjeta. Intente nuevamente.',
                'amount_too_small' => 'El importe es menor al mínimo que acepta la pasarela de pago.',
                default => 'La pasarela de pago rechazó la operación: '.($error['message'] ?? 'error '.$respuesta->status()),
            };
            throw new ReglaNegocioException($mensaje, $respuesta->clientError() ? 422 : 502);
        }

        return (array) $respuesta->json();
    }
}
