<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU5: bandejas de despacho y de Rectorado, lotes de firma y firmas digitales de DESPACHO y RECTORADO.
 * Cada firma apunta (expediente_id) a la versión del expediente que contiene el PDF ya firmado.
 */
class FirmaRepository
{
    /**
     * Bandeja priorizada por urgencia y semáforo SLA.
     */
    public function bandejaDespacho(): Collection
    {
        return DB::table('v_bandeja_despacho')
            ->orderByRaw("CASE prioridad WHEN 'URGENTE' THEN 1 WHEN 'ALTA' THEN 2 ELSE 3 END")
            ->orderByRaw("CASE semaforo WHEN 'ROJO' THEN 1 WHEN 'AMARILLO' THEN 2 ELSE 3 END")
            ->orderBy('fecha_ingreso')
            ->get();
    }

    /**
     * Trámites al exterior derivados a Rectorado, con indicación de si ya tienen la firma del Rector.
     */
    public function bandejaRectorado(): Collection
    {
        return DB::table('tramite as t')
            ->join('usuario as est', 'est.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->leftJoin('v_semaforo_sla as sla', 'sla.tramite_id', '=', 't.id')
            ->where('t.estado', 'DERIVADO_RECTORADO')
            ->select(
                't.id', 't.codigo', 't.destino', 't.pais_destino', 't.institucion_destino', 't.prioridad',
                't.total_fojas', 't.fecha_ingreso',
                DB::raw("est.nombres || ' ' || est.apellidos as solicitante"),
                'est.registro', 'c.nombre as carrera', 'sla.semaforo', 'sla.horas_consumidas', 'sla.plazo_horas',
                DB::raw("EXISTS (SELECT 1 FROM firma_digital f WHERE f.tramite_id = t.id AND f.etapa = 'RECTORADO' AND f.valida) as firmado_rectorado")
            )
            ->orderByRaw("CASE t.prioridad WHEN 'URGENTE' THEN 1 WHEN 'ALTA' THEN 2 ELSE 3 END")
            ->orderBy('t.fecha_ingreso')
            ->get();
    }

    /**
     * trg_lote_validar verifica el rol o la delegación del firmante y su certificado.
     *
     * @param  array{firmante_id: int, certificado_id: int, delegacion_id: ?int, es_remota: bool, ubicacion: string, ip_origen: ?string}  $datos
     */
    public function crearLote(array $datos): int
    {
        return DB::table('lote_firma')->insertGetId($datos);
    }

    /**
     * @param  array{lote_id: int, tramite_id: int, expediente_id: int, etapa: string, hash_documento: string, firma_valor: string}  $datos
     */
    public function registrarFirma(array $datos): int
    {
        return DB::table('firma_digital')->insertGetId($datos);
    }

    /**
     * Última firma válida de una etapa del trámite, con firmante, delegación y lote.
     */
    public function ultimaFirma(int $tramiteId, string $etapa): ?object
    {
        return $this->consultaFirmas()
            ->where('fd.tramite_id', $tramiteId)
            ->where('fd.etapa', $etapa)
            ->orderByDesc('fd.id')
            ->first();
    }

    /**
     * Última firma de despacho y de Rectorado de cada trámite.
     *
     * @param  iterable<int>  $tramiteIds
     * @return Collection<int, Collection<string, object>>
     */
    public function firmasDe(iterable $tramiteIds): Collection
    {
        return $this->consultaFirmas()
            ->whereIn('fd.tramite_id', $tramiteIds)
            ->whereIn('fd.etapa', ['DESPACHO', 'RECTORADO'])
            ->orderByDesc('fd.id')
            ->get()
            ->groupBy('tramite_id')
            ->map(fn (Collection $firmas) => $firmas->unique('etapa')->keyBy('etapa'));
    }

    private function consultaFirmas()
    {
        return DB::table('firma_digital as fd')
            ->join('lote_firma as l', 'l.id', '=', 'fd.lote_id')
            ->join('usuario as u', 'u.id', '=', 'l.firmante_id')
            ->leftJoin('delegacion_autoridad as d', 'd.id', '=', 'l.delegacion_id')
            ->where('fd.valida', true)
            ->select(
                'fd.id', 'fd.tramite_id', 'fd.expediente_id', 'fd.etapa', 'fd.fecha_firma', 'fd.hash_documento',
                'l.es_remota', 'l.ubicacion', 'l.delegacion_id',
                DB::raw("u.nombres || ' ' || u.apellidos as firmante"), 'd.nro_resolucion', 'd.fecha_resolucion'
            );
    }
}
