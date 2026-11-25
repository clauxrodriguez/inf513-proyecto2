<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Portal público de seguimiento (Registro Universitario + C.I.) y semáforo SLA de cada trámite.
 */
class SeguimientoRepository
{
    /**
     * Trámites del estudiante con observaciones pendientes, última actualización y certificado vigente.
     */
    public function consultar(string $registro, string $ci): Collection
    {
        return DB::table('tramite as t')
            ->join('tipo_tramite as tt', 'tt.id', '=', 't.tipo_tramite_id')
            ->join('usuario as u', 'u.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->leftJoin('solicitud_descuento as sd', 'sd.tramite_id', '=', 't.id')
            ->leftJoin('certificado_oficial as co', fn ($join) => $join->on('co.tramite_id', '=', 't.id')->where('co.estado', 'VIGENTE'))
            ->where('u.registro', $registro)
            ->where('u.ci', $ci)
            ->select(
                't.id', 't.codigo', 't.tipo_tramite_id', 'tt.codigo as tipo', 'tt.nombre as tipo_nombre', 't.estado', 't.destino',
                't.pais_destino', 't.fecha_ingreso', 't.fecha_conclusion', 'c.nombre as carrera',
                DB::raw("u.nombres || ' ' || u.apellidos as estudiante"),
                'sd.financiamiento', 'co.numero as certificado_numero', 'co.codigo_verificacion',
                DB::raw("(SELECT COUNT(*) FROM observacion o WHERE o.tramite_id = t.id AND o.estado = 'PENDIENTE') as observaciones_pendientes"),
                DB::raw("(SELECT string_agg(o.descripcion, ' | ') FROM observacion o WHERE o.tramite_id = t.id AND o.estado = 'PENDIENTE') as detalle_observaciones"),
                DB::raw('(SELECT MAX(f.fecha_inicio) FROM tramite_tiempo_fase f WHERE f.tramite_id = t.id) as ultima_actualizacion')
            )
            ->orderByDesc('t.id')
            ->get();
    }

    /**
     * @param  iterable<int>  $tramiteIds
     */
    public function slaDe(iterable $tramiteIds): Collection
    {
        return DB::table('v_semaforo_sla')
            ->whereIn('tramite_id', $tramiteIds)
            ->select('tramite_id', 'semaforo', 'horas_consumidas', 'plazo_horas', 'porcentaje', 'es_final')
            ->get()
            ->keyBy('tramite_id');
    }
}
