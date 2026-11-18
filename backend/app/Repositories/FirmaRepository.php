<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU5: bandejas de despacho y de Rectorado, lotes de firma, firmas digitales y visado de Rectorado.
 */
class FirmaRepository
{
    /**
     * Bandeja priorizada por urgencia, semáforo SLA, tipo y destino (RF-11).
     */
    public function bandejaDespacho(): Collection
    {
        return DB::table('v_bandeja_despacho')
            ->orderByRaw("prioridad DESC, CASE semaforo WHEN 'ROJO' THEN 1 WHEN 'AMARILLO' THEN 2 ELSE 3 END, tipo, destino, fecha_ingreso")
            ->get();
    }

    public function bandejaRectorado(): Collection
    {
        return DB::table('tramite as t')
            ->join('usuario as est', 'est.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->leftJoin('v_semaforo_sla as sla', 'sla.tramite_id', '=', 't.id')
            ->where('t.estado', 'DERIVADO_RECTORADO')
            ->select(
                't.id', 't.codigo', 't.tipo', 't.destino', 't.pais_destino', 't.institucion_destino', 't.prioridad',
                't.total_fojas', 't.fecha_ingreso',
                DB::raw("est.nombres || ' ' || est.apellidos as solicitante"),
                'est.registro', 'c.nombre as carrera', 'sla.semaforo', 'sla.horas_consumidas', 'sla.plazo_horas'
            )
            ->orderByDesc('t.prioridad')
            ->orderBy('t.fecha_ingreso')
            ->get();
    }

    /**
     * Registra el lote; trg_lote_validar fija la calidad (TITULAR/INTERINO) y valida delegación y certificado.
     *
     * @param  array{firmante_id: int, certificado_id: int, es_remota: bool, ubicacion: string, ip_origen: ?string}  $datos
     */
    public function crearLote(array $datos): int
    {
        return DB::table('lote_firma')->insertGetId($datos);
    }

    public function buscarLote(int $loteId): ?object
    {
        return DB::table('lote_firma')->where('id', $loteId)->first();
    }

    /**
     * Ejecuta fn_firmar_lote: registra una firma por expediente y avanza el flujo (atómico).
     *
     * @param  array<int, array{tramite_id: int, firma: string, hash: string, ruta: string, hash_firmado: string}>  $firmas
     */
    public function firmarLote(int $loteId, array $firmas): void
    {
        DB::selectOne(
            'SELECT fn_firmar_lote(?::int, ?::int[], ?::text[], ?::text[], ?::text[], ?::text[]) AS total',
            [
                $loteId,
                $this->arregloPostgres(array_column($firmas, 'tramite_id')),
                $this->arregloPostgres(array_column($firmas, 'firma')),
                $this->arregloPostgres(array_column($firmas, 'hash')),
                $this->arregloPostgres(array_column($firmas, 'ruta')),
                $this->arregloPostgres(array_column($firmas, 'hash_firmado')),
            ]
        );
    }

    public function firmaVigente(int $tramiteId): ?object
    {
        return DB::table('firma_digital')->where('tramite_id', $tramiteId)->where('valida', true)->orderByDesc('id')->first();
    }

    /**
     * Última firma válida de cada trámite, con firmante, calidad y resolución de la delegación.
     *
     * @param  iterable<int>  $tramiteIds
     */
    public function firmasVigentesDe(iterable $tramiteIds): Collection
    {
        return DB::table('firma_digital as fd')
            ->join('lote_firma as l', 'l.id', '=', 'fd.lote_id')
            ->join('usuario as u', 'u.id', '=', 'l.firmante_id')
            ->leftJoin('delegacion_autoridad as d', 'd.id', '=', 'l.delegacion_id')
            ->whereIn('fd.tramite_id', $tramiteIds)
            ->where('fd.valida', true)
            ->orderByDesc('fd.id')
            ->select(
                'fd.tramite_id', 'fd.fecha_firma', 'fd.hash_documento', 'l.calidad', 'l.es_remota', 'l.ubicacion',
                DB::raw("u.nombres || ' ' || u.apellidos as firmante"), 'd.nro_resolucion', 'd.tipo_resolucion'
            )
            ->get()
            ->unique('tramite_id')
            ->keyBy('tramite_id');
    }

    public function visadoDe(int $tramiteId): ?object
    {
        return DB::table('visado_rectorado')->where('tramite_id', $tramiteId)->first();
    }

    /**
     * @param  iterable<int>  $tramiteIds
     */
    public function visadosDe(iterable $tramiteIds): Collection
    {
        return DB::table('visado_rectorado as v')
            ->join('usuario as u', 'u.id', '=', 'v.usuario_id')
            ->whereIn('v.tramite_id', $tramiteIds)
            ->select('v.tramite_id', 'v.fecha', 'v.observacion', 'v.hash_sha256', DB::raw("u.nombres || ' ' || u.apellidos as visado_por"))
            ->get()
            ->keyBy('tramite_id');
    }

    /**
     * @param  array{tramite_id: int, usuario_id: int, ruta_pdf_sellado: string, hash_sha256: string, observacion: ?string}  $datos
     */
    public function crearVisado(array $datos): void
    {
        DB::table('visado_rectorado')->insert($datos);
    }

    /**
     * @param  array<int, int|string>  $valores
     */
    private function arregloPostgres(array $valores): string
    {
        $elementos = array_map(
            fn ($valor) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $valor).'"',
            $valores
        );

        return '{'.implode(',', $elementos).'}';
    }
}
