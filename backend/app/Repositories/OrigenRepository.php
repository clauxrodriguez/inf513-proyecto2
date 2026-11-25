<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Firma en cadena en el origen facultativo: documentos cuya siguiente firma pendiente
 * (catalogo_firma_orden) corresponde a una unidad, y firmas ORIGEN ya registradas.
 */
class OrigenRepository
{
    /**
     * Documentos de trámites en origen con su siguiente firma pendiente.
     *
     * @param  array{unidad?: string, carreras?: list<int>, facultades?: list<int>, documentos?: list<int>}  $filtros
     */
    public function documentosPendientes(array $filtros): Collection
    {
        $condiciones = [];
        $parametros = [];

        if (isset($filtros['unidad'])) {
            $condiciones[] = 'sig.unidad = ?';
            $parametros[] = $filtros['unidad'];
        }
        foreach (['carreras' => 't.carrera_id', 'facultades' => 'c.facultad_id', 'documentos' => 'td.id'] as $clave => $columna) {
            if (array_key_exists($clave, $filtros)) {
                $ids = array_map('intval', $filtros[$clave]);
                if ($ids === []) {
                    return collect();
                }
                $condiciones[] = $columna.' IN ('.implode(',', array_fill(0, count($ids), '?')).')';
                array_push($parametros, ...$ids);
            }
        }
        $filtrosSql = $condiciones === [] ? '' : ' AND '.implode(' AND ', $condiciones);

        return collect(DB::select("
            SELECT td.id, td.tramite_id, td.catalogo_documento_id, td.cantidad_paginas, td.cantidad_materias,
                   td.archivo_ruta, td.estado,
                   cd.codigo AS documento_codigo, cd.nombre AS documento_nombre,
                   t.codigo AS tramite_codigo, t.estado AS tramite_estado, t.destino, t.prioridad, t.fecha_ingreso,
                   est.nombres || ' ' || est.apellidos AS estudiante, est.ci, est.registro,
                   c.nombre AS carrera, f.nombre AS facultad,
                   sig.orden AS orden_pendiente, sig.cargo_firmante, sig.unidad AS unidad_pendiente,
                   (SELECT COUNT(*) FROM catalogo_firma_orden x WHERE x.catalogo_documento_id = td.catalogo_documento_id) AS total_firmas
              FROM tramite_documento td
              JOIN tramite t             ON t.id = td.tramite_id
              JOIN catalogo_documento cd ON cd.id = td.catalogo_documento_id
              JOIN usuario est           ON est.id = t.solicitante_id
              JOIN carrera c             ON c.id = t.carrera_id
              JOIN facultad f            ON f.id = c.facultad_id
              JOIN LATERAL (
                   SELECT cfo.orden, cfo.cargo_firmante, uo.codigo AS unidad
                     FROM catalogo_firma_orden cfo
                     JOIN unidad_origen uo ON uo.id = cfo.unidad_origen_id
                    WHERE cfo.catalogo_documento_id = td.catalogo_documento_id
                      AND NOT EXISTS (SELECT 1 FROM firma_digital fd
                                       WHERE fd.tramite_documento_id = td.id AND fd.etapa = 'ORIGEN'
                                         AND fd.valida AND fd.orden_firma = cfo.orden)
                    ORDER BY cfo.orden
                    LIMIT 1) sig ON TRUE
             WHERE t.estado IN ('SOLICITADO_ORIGEN', 'EN_FIRMA_ORIGEN')
               AND td.estado <> 'RECHAZADO'
               AND NOT td.firmas_origen_ok
               {$filtrosSql}
             ORDER BY CASE t.prioridad WHEN 'URGENTE' THEN 1 WHEN 'ALTA' THEN 2 ELSE 3 END, t.fecha_ingreso, td.id
        ", $parametros));
    }

    /**
     * Firmas en origen ya registradas, agrupadas por documento del trámite.
     *
     * @param  iterable<int>  $documentoIds
     */
    public function firmasDe(iterable $documentoIds): Collection
    {
        return DB::table('firma_digital as fd')
            ->join('lote_firma as l', 'l.id', '=', 'fd.lote_id')
            ->join('usuario as u', 'u.id', '=', 'l.firmante_id')
            ->whereIn('fd.tramite_documento_id', $documentoIds)
            ->where('fd.etapa', 'ORIGEN')
            ->where('fd.valida', true)
            ->select(
                'fd.tramite_documento_id', 'fd.orden_firma', 'fd.fecha_firma', 'fd.hash_documento', 'l.es_remota',
                DB::raw("u.nombres || ' ' || u.apellidos as firmante"), 'u.cargo'
            )
            ->orderBy('fd.orden_firma')
            ->get()
            ->groupBy('tramite_documento_id');
    }

    /**
     * El trigger trg_firma_origen_check marca firmas_origen_ok cuando el documento completa su cadena.
     *
     * @param  array{lote_id: int, tramite_id: int, tramite_documento_id: int, orden_firma: int, hash_documento: string, firma_valor: string}  $datos
     */
    public function registrarFirma(array $datos): void
    {
        DB::table('firma_digital')->insert([...$datos, 'etapa' => 'ORIGEN']);
    }
}
