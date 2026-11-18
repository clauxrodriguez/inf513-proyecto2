<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU7 - Semáforo SLA (v_semaforo_sla), tiempos por fase, alertas y parametrización (sla_config).
 */
class SlaRepository
{
    /**
     * fn_generar_alertas_sla: alerta única por nivel (PREVENTIVA / VENCIDA) y notificación a Recepción.
     * Devuelve la cantidad de alertas nuevas.
     */
    public function generarAlertas(): int
    {
        return (int) DB::selectOne('SELECT fn_generar_alertas_sla() AS nuevas')->nuevas;
    }

    /**
     * Trámites en curso (no finalizados) con su consumo del plazo, ordenados del más crítico al menos.
     */
    public function tramitesEnCurso(): Collection
    {
        return DB::table('v_semaforo_sla as s')
            ->join('tramite as t', 't.id', '=', 's.tramite_id')
            ->join('usuario as u', 'u.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->where('s.es_final', false)
            ->select(
                's.tramite_id', 's.codigo', 's.tipo', 's.estado', 's.prioridad', 's.fecha_ingreso',
                's.horas_consumidas', 's.plazo_horas', 's.porcentaje', 's.semaforo',
                't.destino', 't.pais_destino',
                DB::raw("u.nombres || ' ' || u.apellidos as solicitante"), 'c.nombre as carrera',
                DB::raw('fn_estado_nombre(t.estado) as estado_nombre'),
                DB::raw('fn_estado_cuenta_sla(t.estado) as reloj_activo'),
                DB::raw('(SELECT f.fecha_inicio FROM tramite_tiempo_fase f WHERE f.tramite_id = t.id AND f.fecha_fin IS NULL ORDER BY f.id DESC LIMIT 1) as fase_desde')
            )
            ->orderByDesc('s.porcentaje')
            ->orderBy('s.fecha_ingreso')
            ->get();
    }

    /**
     * @return array{en_curso: int, verde: int, amarillo: int, rojo: int, detenidos: int, concluidos: int, concluidos_en_plazo: int, promedio_horas_concluidos: ?float}
     */
    public function resumen(): array
    {
        $fila = DB::selectOne("
            SELECT COUNT(*) FILTER (WHERE NOT es_final)                                   AS en_curso,
                   COUNT(*) FILTER (WHERE NOT es_final AND semaforo = 'VERDE')            AS verde,
                   COUNT(*) FILTER (WHERE NOT es_final AND semaforo = 'AMARILLO')         AS amarillo,
                   COUNT(*) FILTER (WHERE NOT es_final AND semaforo = 'ROJO')             AS rojo,
                   COUNT(*) FILTER (WHERE estado = 'OBSERVADO')                           AS detenidos,
                   COUNT(*) FILTER (WHERE estado = 'CONCLUIDO')                           AS concluidos,
                   COUNT(*) FILTER (WHERE estado = 'CONCLUIDO' AND horas_consumidas < plazo_horas) AS concluidos_en_plazo,
                   ROUND(AVG(horas_consumidas) FILTER (WHERE estado = 'CONCLUIDO'), 2)    AS promedio_horas_concluidos
              FROM v_semaforo_sla
        ");

        return [
            'en_curso' => (int) $fila->en_curso,
            'verde' => (int) $fila->verde,
            'amarillo' => (int) $fila->amarillo,
            'rojo' => (int) $fila->rojo,
            'detenidos' => (int) $fila->detenidos,
            'concluidos' => (int) $fila->concluidos,
            'concluidos_en_plazo' => (int) $fila->concluidos_en_plazo,
            'promedio_horas_concluidos' => $fila->promedio_horas_concluidos === null ? null : (float) $fila->promedio_horas_concluidos,
        ];
    }

    /**
     * SF-1: historial de fases del trámite con horas hábiles (la fase abierta se calcula hasta ahora).
     */
    public function fasesDe(int $tramiteId): Collection
    {
        return DB::table('tramite_tiempo_fase as f')
            ->leftJoin('usuario as u', 'u.id', '=', 'f.responsable_id')
            ->where('f.tramite_id', $tramiteId)
            ->select(
                'f.id', 'f.estado', 'f.fecha_inicio', 'f.fecha_fin',
                DB::raw('fn_estado_nombre(f.estado) as estado_nombre'),
                DB::raw('COALESCE(f.horas_habiles, fn_horas_habiles(f.fecha_inicio, LOCALTIMESTAMP)) as horas_habiles'),
                DB::raw('f.fecha_fin IS NULL as en_curso'),
                DB::raw('fn_estado_cuenta_sla(f.estado) as cuenta_sla'),
                DB::raw("u.nombres || ' ' || u.apellidos as responsable")
            )
            ->orderBy('f.id')
            ->get();
    }

    public function semaforoDe(int $tramiteId): ?object
    {
        return DB::table('v_semaforo_sla')->where('tramite_id', $tramiteId)->first();
    }

    // ------------------------------------------------------------------ alertas

    public function alertas(bool $incluirAtendidas): Collection
    {
        return DB::table('alerta_sla as a')
            ->join('tramite as t', 't.id', '=', 'a.tramite_id')
            ->leftJoin('v_semaforo_sla as s', 's.tramite_id', '=', 'a.tramite_id')
            ->leftJoin('usuario as u', 'u.id', '=', 'a.atendida_por')
            ->when(! $incluirAtendidas, fn ($query) => $query->where('a.atendida', false))
            ->select(
                'a.id', 'a.tramite_id', 'a.tipo', 'a.horas_transcurridas', 'a.plazo_horas', 'a.fecha', 'a.atendida',
                'a.fecha_atencion', DB::raw("u.nombres || ' ' || u.apellidos as atendida_por_nombre"),
                't.codigo', 't.estado', 's.semaforo', 's.horas_consumidas', 's.es_final'
            )
            ->orderBy('a.atendida')
            ->orderByRaw("CASE a.tipo WHEN 'VENCIDA' THEN 1 ELSE 2 END")
            ->orderByDesc('a.fecha')
            ->get();
    }

    public function buscarAlerta(int $alertaId): ?object
    {
        return DB::table('alerta_sla')->where('id', $alertaId)->first();
    }

    /**
     * trg_alerta_atender registra la fecha y el usuario, e impide reabrirla.
     */
    public function marcarAtendida(int $alertaId, int $usuarioId): void
    {
        DB::table('alerta_sla')->where('id', $alertaId)->update(['atendida' => true, 'atendida_por' => $usuarioId]);
    }

    // ------------------------------------------------------------------ parametrización (SF-3)

    public function configuracion(): Collection
    {
        return DB::table('sla_config')->orderByRaw('tipo NULLS FIRST')->get();
    }

    /**
     * Crea o actualiza la configuración general (tipo null) o específica de un tipo de trámite.
     */
    public function guardarConfiguracion(?string $tipo, float $plazoHoras, float $porcentajeAlerta): void
    {
        $datos = ['plazo_horas' => $plazoHoras, 'porcentaje_alerta' => $porcentajeAlerta];
        $consulta = DB::table('sla_config')->when(
            $tipo === null,
            fn ($query) => $query->whereNull('tipo'),
            fn ($query) => $query->where('tipo', $tipo)
        );

        if ($consulta->exists()) {
            $consulta->update($datos);
        } else {
            DB::table('sla_config')->insert(['tipo' => $tipo, ...$datos]);
        }
    }

    public function eliminarConfiguracion(string $tipo): int
    {
        return DB::table('sla_config')->where('tipo', $tipo)->delete();
    }
}
