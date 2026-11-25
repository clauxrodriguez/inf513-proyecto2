<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora inalterable con cadena de hash SHA-256 (CU4) y bitácora de accesos web.
 */
class AuditoriaRepository
{
    /**
     * Registra un evento de negocio con fn_registrar_bitacora (el trigger encadena el hash y toma el usuario).
     * El evento referencia con FK explícitas el trámite y, según la acción, la delegación, el certificado
     * oficial o el pago en línea que afectó.
     *
     * @param  array<string, mixed>  $detalle
     */
    public function registrarEvento(
        ?int $tramiteId,
        string $accion,
        array $detalle,
        ?int $delegacionId = null,
        ?int $certificadoId = null,
        ?int $transaccionPagoId = null,
    ): void {
        DB::statement('SELECT fn_registrar_bitacora(?, ?, ?::jsonb, ?, ?, ?)', [
            $tramiteId, $accion, json_encode($detalle, JSON_UNESCAPED_UNICODE), $delegacionId, $certificadoId, $transaccionPagoId,
        ]);
    }

    public function paginarBitacora(?int $tramiteId, int $porPagina): LengthAwarePaginator
    {
        return DB::table('bitacora as b')
            ->leftJoin('usuario as u', 'u.id', '=', 'b.usuario_id')
            ->leftJoin('tramite as t', 't.id', '=', 'b.tramite_id')
            ->leftJoin('delegacion_autoridad as d', 'd.id', '=', 'b.delegacion_id')
            ->leftJoin('certificado_oficial as co', 'co.id', '=', 'b.certificado_id')
            ->when($tramiteId !== null, fn ($query) => $query->where('b.tramite_id', $tramiteId))
            ->select('b.*', 't.codigo as tramite_codigo', 'd.nro_resolucion as delegacion_resolucion', 'co.numero as certificado_numero',
                DB::raw("u.nombres || ' ' || u.apellidos as usuario_nombre"))
            ->orderByDesc('b.id')
            ->paginate($porPagina, ['*'], 'pagina');
    }

    public function accesosRecientes(int $limite = 100): Collection
    {
        return DB::table('bitacora_acceso as a')
            ->leftJoin('usuario as u', 'u.id', '=', 'a.usuario_id')
            ->select('a.*', DB::raw("u.nombres || ' ' || u.apellidos as usuario_nombre"))
            ->orderByDesc('a.id')
            ->limit($limite)
            ->get();
    }

    /**
     * Recalcula la cadena de hash (misma fórmula que fn_bitacora_hash) y devuelve el id del primer
     * registro alterado, o null si la bitácora está íntegra.
     */
    public function primerRegistroAlterado(): ?int
    {
        $anterior = str_repeat('0', 64);
        $alterado = null;

        DB::table('bitacora')
            ->select('id', 'tramite_id', 'usuario_id', 'accion', 'delegacion_id', 'certificado_id', 'transaccion_pago_id', 'hash_anterior', 'hash_actual',
                DB::raw('detalle::text as detalle_texto'), DB::raw('fecha::text as fecha_texto'))
            ->orderBy('id')
            ->chunk(500, function (Collection $registros) use (&$anterior, &$alterado): bool {
                foreach ($registros as $registro) {
                    $esperado = hash('sha256', implode('|', [
                        $anterior, $registro->id, $registro->tramite_id ?? '', $registro->usuario_id ?? '', $registro->accion,
                        $registro->delegacion_id ?? '', $registro->certificado_id ?? '', $registro->transaccion_pago_id ?? '',
                        $registro->detalle_texto ?? '', $registro->fecha_texto,
                    ]));

                    if ($registro->hash_anterior !== $anterior || $registro->hash_actual !== $esperado) {
                        $alterado = (int) $registro->id;

                        return false;
                    }
                    $anterior = $registro->hash_actual;
                }

                return true;
            });

        return $alterado;
    }
}
