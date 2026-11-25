<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tarjetas tokenizadas por usuario (metodo_pago_usuario) y transacciones de pago en línea (transaccion_pago).
 */
class PagoRepository
{
    // ------------------------------------------------------------------ métodos de pago

    public function clienteStripeDe(int $usuarioId): ?string
    {
        return DB::table('usuario')->where('id', $usuarioId)->value('stripe_customer_id');
    }

    public function asignarClienteStripe(int $usuarioId, string $clienteId): void
    {
        DB::table('usuario')->where('id', $usuarioId)->update(['stripe_customer_id' => $clienteId]);
    }

    public function metodosDe(int $usuarioId): Collection
    {
        return DB::table('metodo_pago_usuario')
            ->where('usuario_id', $usuarioId)
            ->where('activo', true)
            ->orderByDesc('predeterminado')
            ->orderByDesc('id')
            ->get(['id', 'marca', 'ultimos_cuatro', 'mes_expiracion', 'anio_expiracion', 'predeterminado', 'fecha_registro']);
    }

    public function buscarMetodo(int $id, int $usuarioId): ?object
    {
        return DB::table('metodo_pago_usuario')->where('id', $id)->where('usuario_id', $usuarioId)->where('activo', true)->first();
    }

    /**
     * @param  array{usuario_id: int, stripe_payment_method_id: string, marca: string, ultimos_cuatro: string, mes_expiracion: int, anio_expiracion: int}  $datos
     */
    public function guardarMetodo(array $datos): int
    {
        $esPrimero = ! DB::table('metodo_pago_usuario')->where('usuario_id', $datos['usuario_id'])->where('activo', true)->exists();

        return DB::table('metodo_pago_usuario')->insertGetId([...$datos, 'predeterminado' => $esPrimero]);
    }

    public function marcarPredeterminado(int $id, int $usuarioId): void
    {
        DB::table('metodo_pago_usuario')->where('usuario_id', $usuarioId)->update(['predeterminado' => false]);
        DB::table('metodo_pago_usuario')->where('id', $id)->where('usuario_id', $usuarioId)->update(['predeterminado' => true]);
    }

    /**
     * Baja lógica: la tarjeta se conserva para el historial de pagos, pero ya no puede usarse.
     */
    public function desactivarMetodo(int $id, int $usuarioId): void
    {
        DB::table('metodo_pago_usuario')->where('id', $id)->where('usuario_id', $usuarioId)->update(['activo' => false, 'predeterminado' => false]);
    }

    // ------------------------------------------------------------------ transacciones

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crearTransaccion(array $datos): int
    {
        return DB::table('transaccion_pago')->insertGetId($datos);
    }

    public function buscarTransaccion(int $id): ?object
    {
        return DB::table('transaccion_pago')->where('id', $id)->first();
    }

    public function existeIntent(string $paymentIntentId): bool
    {
        return DB::table('transaccion_pago')->where('stripe_payment_intent_id', $paymentIntentId)->exists();
    }

    /**
     * Bloquea la transacción hasta el fin de la transacción de BD (evita registrar dos veces el mismo pago).
     */
    public function bloquearPorIntent(string $paymentIntentId): ?object
    {
        return DB::table('transaccion_pago')->where('stripe_payment_intent_id', $paymentIntentId)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizarTransaccion(int $id, array $datos): void
    {
        DB::table('transaccion_pago')->where('id', $id)->update($datos);
    }

    public function tienePagoExitoso(int $tramiteId): bool
    {
        return DB::table('transaccion_pago')->where('tramite_id', $tramiteId)->where('estado', 'EXITOSO')->exists();
    }

    /**
     * Historial de pagos (del estudiante, o todos para la Ventanilla), con su trámite y tarjeta.
     */
    public function historial(?int $usuarioId, int $limite = 200): Collection
    {
        return DB::table('transaccion_pago as tp')
            ->join('tramite as t', 't.id', '=', 'tp.tramite_id')
            ->join('usuario as u', 'u.id', '=', 'tp.usuario_id')
            ->leftJoin('metodo_pago_usuario as m', 'm.id', '=', 'tp.metodo_pago_id')
            ->when($usuarioId !== null, fn ($consulta) => $consulta->where('tp.usuario_id', $usuarioId))
            ->select(
                'tp.id', 'tp.tramite_id', 't.codigo as tramite_codigo', 'tp.monto', 'tp.moneda', 'tp.estado', 'tp.mensaje_error',
                'tp.recibo_url', 'tp.stripe_payment_intent_id', 'tp.fecha_creacion', 'tp.fecha_pago',
                DB::raw("u.nombres || ' ' || u.apellidos as pagador"), 'm.marca', 'm.ultimos_cuatro'
            )
            ->orderByDesc('tp.id')
            ->limit($limite)
            ->get();
    }
}
