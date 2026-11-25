<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Pagos en línea al contado con Stripe: billetera de tarjetas, pago del arancel, historial y webhook.
 */
class PagoController extends Controller
{
    public function __construct(private PagoService $pagos) {}

    public function metodos(Request $request): JsonResponse
    {
        return response()->json($this->pagos->metodos($request->user()));
    }

    public function prepararTarjeta(Request $request): JsonResponse
    {
        return response()->json($this->pagos->prepararTarjeta($request->user()));
    }

    public function guardarTarjeta(Request $request): JsonResponse
    {
        $datos = $request->validate(
            ['payment_method_id' => ['required', 'string', 'starts_with:pm_', 'max:100']],
            ['payment_method_id.required' => 'No se recibió la tarjeta tokenizada.', 'payment_method_id.starts_with' => 'El identificador de la tarjeta no es válido.'],
        );

        $id = $this->pagos->guardarTarjeta($request->user(), $datos['payment_method_id']);

        return response()->json(['message' => 'Tarjeta guardada en su billetera.', 'id' => $id], 201);
    }

    public function predeterminar(Request $request, int $id): JsonResponse
    {
        $this->pagos->predeterminar($request->user(), $id);

        return response()->json(['message' => 'Tarjeta marcada como predeterminada.']);
    }

    public function eliminarTarjeta(Request $request, int $id): JsonResponse
    {
        $this->pagos->eliminarTarjeta($request->user(), $id);

        return response()->json(['message' => 'Tarjeta eliminada de su billetera.']);
    }

    public function iniciarPago(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate(['metodo_pago_id' => ['nullable', 'integer']]);

        return response()->json($this->pagos->iniciarPago($request->user(), $id, $datos['metodo_pago_id'] ?? null), 201);
    }

    public function confirmarPago(Request $request, int $transaccionId): JsonResponse
    {
        $resultado = $this->pagos->confirmarPago($request->user(), $transaccionId);

        return response()->json([
            'message' => match ($resultado['estado']) {
                'EXITOSO' => 'Pago realizado con éxito. El recibo quedó registrado en su trámite.',
                'REQUIERE_ACCION' => 'El banco requiere una verificación adicional para completar el pago.',
                'FALLIDO' => 'El pago no se completó: '.($resultado['transaccion']->mensaje_error ?: 'la tarjeta fue rechazada.'),
                'CANCELADO' => 'El pago fue cancelado.',
                default => 'El pago está en proceso; se actualizará en unos instantes.',
            },
            ...$resultado,
        ]);
    }

    public function historial(Request $request): JsonResponse
    {
        return response()->json($this->pagos->historial($request->user()));
    }

    /**
     * Webhook de Stripe (sin sesión): la firma se verifica con STRIPE_WEBHOOK_SECRET.
     */
    public function webhook(Request $request): Response
    {
        $this->pagos->procesarWebhook($request->getContent(), $request->header('Stripe-Signature'));

        return response()->noContent();
    }
}
