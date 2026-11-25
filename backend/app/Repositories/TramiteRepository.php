<?php

namespace App\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trámite y sus partes: documentos, requisitos, recibos de caja, solicitud de descuento y observaciones.
 * Las consultas exponen el código del tipo de trámite como "tipo" (LEGALIZACION / DESCUENTO_DIPLOMADO).
 */
class TramiteRepository
{
    public function buscar(int $id): ?object
    {
        return $this->consultaBase()->where('t.id', $id)->first();
    }

    /**
     * @param  array<int>  $ids
     */
    public function buscarVarios(array $ids): Collection
    {
        return $this->consultaBase()->whereIn('t.id', $ids)->get()->keyBy('id');
    }

    /**
     * Trámites con solicitante, carrera y semáforo SLA.
     *
     * @param  list<string>  $estados
     */
    public function listar(?int $solicitanteId = null, array $estados = []): Collection
    {
        return $this->consultaConSolicitante()
            ->when($solicitanteId !== null, fn ($query) => $query->where('t.solicitante_id', $solicitanteId))
            ->when($estados !== [], fn ($query) => $query->whereIn('t.estado', $estados))
            ->orderByDesc('t.id')
            ->get();
    }

    public function buscarDetalle(int $id): ?object
    {
        return $this->consultaConSolicitante()->where('t.id', $id)->first();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): int
    {
        return DB::table('tramite')->insertGetId($datos);
    }

    /**
     * Cambia el estado; con $soloSiEstado actualiza únicamente si el trámite está en ese estado.
     * La máquina de estados de PostgreSQL (trg_tramite_validar_estado) valida la transición.
     */
    public function cambiarEstado(int $id, string $estado, ?string $soloSiEstado = null): int
    {
        return DB::table('tramite')
            ->where('id', $id)
            ->when($soloSiEstado !== null, fn ($query) => $query->where('estado', $soloSiEstado))
            ->update(['estado' => $estado]);
    }

    // ------------------------------------------------------------------ documentos

    /**
     * @param  array<string, mixed>  $datos
     */
    public function agregarDocumento(array $datos): int
    {
        return DB::table('tramite_documento')->insertGetId($datos);
    }

    public function buscarDocumento(int $id): ?object
    {
        return DB::table('tramite_documento')->where('id', $id)->first();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizarDocumento(int $id, array $datos): void
    {
        DB::table('tramite_documento')->where('id', $id)->update($datos);
    }

    public function documentoPerteneceA(int $documentoId, int $tramiteId): bool
    {
        return DB::table('tramite_documento')->where('id', $documentoId)->where('tramite_id', $tramiteId)->exists();
    }

    /**
     * True si todos los documentos vigentes del trámite completaron su cadena de firmas en origen.
     */
    public function documentosFirmadosEnOrigen(int $tramiteId): bool
    {
        return ! DB::table('tramite_documento')
            ->where('tramite_id', $tramiteId)
            ->where('estado', '<>', 'RECHAZADO')
            ->where('firmas_origen_ok', false)
            ->exists();
    }

    /**
     * Foliación continua en PostgreSQL; devuelve el total de fojas.
     */
    public function asignarFolios(int $tramiteId): int
    {
        return (int) (DB::selectOne('SELECT fn_asignar_folios(?) AS fojas', [$tramiteId])->fojas ?? 0);
    }

    /**
     * Documentos de uno o varios trámites con datos del catálogo, agrupados por trámite.
     *
     * @param  iterable<int>  $tramiteIds
     */
    public function documentosDe(iterable $tramiteIds): Collection
    {
        return DB::table('tramite_documento as td')
            ->join('catalogo_documento as cd', 'cd.id', '=', 'td.catalogo_documento_id')
            ->whereIn('td.tramite_id', $tramiteIds)
            ->select('td.*', 'cd.codigo', 'cd.nombre as documento_nombre', 'cd.tipo_cobro', 'cd.tarifa')
            ->orderBy('td.id')
            ->get()
            ->groupBy('tramite_id');
    }

    /**
     * Documentos no rechazados para la carátula y el ensamblaje del legajo.
     */
    public function documentosParaLegajo(int $tramiteId): Collection
    {
        return DB::table('tramite_documento as td')
            ->join('catalogo_documento as cd', 'cd.id', '=', 'td.catalogo_documento_id')
            ->where('td.tramite_id', $tramiteId)
            ->where('td.estado', '<>', 'RECHAZADO')
            ->orderBy('td.id')
            ->select('td.*', 'cd.nombre')
            ->get();
    }

    /**
     * Cantidad de documentos y si todos completaron sus firmas en origen, por trámite.
     *
     * @param  iterable<int>  $tramiteIds
     */
    public function resumenDocumentos(iterable $tramiteIds): Collection
    {
        return DB::table('tramite_documento')
            ->whereIn('tramite_id', $tramiteIds)
            ->where('estado', '<>', 'RECHAZADO')
            ->selectRaw('tramite_id, COUNT(*) AS total_documentos, BOOL_AND(firmas_origen_ok) AS firmas_origen_ok')
            ->groupBy('tramite_id')
            ->get()
            ->keyBy('tramite_id');
    }

    // ------------------------------------------------------------------ requisitos

    /**
     * @param  iterable<int>  $tramiteIds
     */
    public function requisitosDe(iterable $tramiteIds): Collection
    {
        return DB::table('tramite_requisito as tr')
            ->join('requisito as r', 'r.id', '=', 'tr.requisito_id')
            ->whereIn('tr.tramite_id', $tramiteIds)
            ->select('tr.*', 'r.codigo', 'r.descripcion', 'r.obligatorio')
            ->orderBy('r.id')
            ->get()
            ->groupBy('tramite_id');
    }

    public function tieneRequisito(int $tramiteId, int $requisitoId): bool
    {
        return DB::table('tramite_requisito')->where('tramite_id', $tramiteId)->where('requisito_id', $requisitoId)->exists();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizarRequisito(int $tramiteId, int $requisitoId, array $datos): void
    {
        DB::table('tramite_requisito')->where('tramite_id', $tramiteId)->where('requisito_id', $requisitoId)->update($datos);
    }

    /**
     * Marca como cumplido un requisito del trámite por su código (p. ej. COMPROBANTE tras un pago en línea).
     */
    public function marcarRequisitoCumplido(int $tramiteId, string $codigoRequisito, int $usuarioId): void
    {
        DB::table('tramite_requisito')
            ->where('tramite_id', $tramiteId)
            ->whereIn('requisito_id', DB::table('requisito')->where('codigo', $codigoRequisito)->select('id'))
            ->update(['cumplido' => true, 'verificado_por' => $usuarioId, 'fecha_verificacion' => now()]);
    }

    // ------------------------------------------------------------------ recibos de Caja / Tesorería

    /**
     * El trigger trg_comprobante_total recalcula tramite.importe_pagado.
     *
     * @param  array{tramite_id: int, codigo_recibo: string, concepto: string, importe: float|string, fecha_pago: string}  $datos
     */
    public function crearComprobante(array $datos): int
    {
        return DB::table('comprobante_caja')->insertGetId($datos);
    }

    public function buscarComprobante(int $id): ?object
    {
        return DB::table('comprobante_caja')->where('id', $id)->first();
    }

    public function eliminarComprobante(int $id): void
    {
        DB::table('comprobante_caja')->where('id', $id)->delete();
    }

    public function comprobantesDe(int $tramiteId): Collection
    {
        return DB::table('comprobante_caja as cc')
            ->leftJoin('usuario as u', 'u.id', '=', 'cc.registrado_por')
            ->where('cc.tramite_id', $tramiteId)
            ->select('cc.*', DB::raw("u.nombres || ' ' || u.apellidos as registrado_por_nombre"))
            ->orderBy('cc.id')
            ->get();
    }

    // ------------------------------------------------------------------ descuento de diplomado

    /**
     * El trigger trg_solicitud_descuento calcula fecha_limite, dentro_plazo y financiamiento.
     *
     * @param  array<string, mixed>  $datos
     */
    public function agregarDescuento(array $datos): void
    {
        DB::table('solicitud_descuento')->insert($datos);
    }

    /**
     * @param  iterable<int>  $tramiteIds
     */
    public function descuentosDe(iterable $tramiteIds): Collection
    {
        return DB::table('solicitud_descuento')->whereIn('tramite_id', $tramiteIds)->get()->keyBy('tramite_id');
    }

    // ------------------------------------------------------------------ observaciones

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crearObservacion(array $datos): int
    {
        return DB::table('observacion')->insertGetId($datos);
    }

    public function buscarObservacionConTramite(int $observacionId): ?object
    {
        return DB::table('observacion as o')
            ->join('tramite as t', 't.id', '=', 'o.tramite_id')
            ->where('o.id', $observacionId)
            ->select('o.*', 't.codigo', 't.estado as tramite_estado', 't.solicitante_id', 't.total_fojas')
            ->first();
    }

    public function marcarObservacionSubsanada(int $observacionId): void
    {
        DB::table('observacion')->where('id', $observacionId)->update(['estado' => 'SUBSANADA', 'fecha_subsanacion' => now()]);
    }

    public function tieneObservacionesPendientes(int $tramiteId): bool
    {
        return DB::table('observacion')->where('tramite_id', $tramiteId)->where('estado', 'PENDIENTE')->exists();
    }

    public function observacionesDe(int $tramiteId): Collection
    {
        return DB::table('observacion as o')
            ->leftJoin('tramite_documento as td', 'td.id', '=', 'o.tramite_documento_id')
            ->leftJoin('catalogo_documento as cd', 'cd.id', '=', 'td.catalogo_documento_id')
            ->leftJoin('usuario as u', 'u.id', '=', 'o.registrado_por')
            ->where('o.tramite_id', $tramiteId)
            ->select('o.*', 'cd.nombre as documento_nombre', DB::raw("u.nombres || ' ' || u.apellidos as registrado_por_nombre"))
            ->orderByDesc('o.id')
            ->get();
    }

    // ------------------------------------------------------------------ flujo y certificado

    /**
     * Pasos de la guía procesal que aplican al trámite; un paso está completado si el trámite ya
     * pasó por su estado asociado (tramite_tiempo_fase cerrada) o si el trámite concluyó.
     */
    public function flujoDe(object $tramite): Collection
    {
        return DB::table('flujo_paso as fp')
            ->join('unidad_origen as u', 'u.id', '=', 'fp.unidad_id')
            ->where('fp.tipo_tramite_id', $tramite->tipo_tramite_id)
            ->where(fn ($query) => $query->whereNull('fp.destino')->orWhere('fp.destino', $tramite->destino))
            ->select(
                'fp.orden', 'u.nombre as unidad', 'fp.nombre as paso', 'fp.descripcion', 'fp.firma_requerida', 'fp.sello_requerido',
                'fp.estado_asociado',
            )
            ->selectRaw(
                '(EXISTS (SELECT 1 FROM tramite_tiempo_fase f WHERE f.tramite_id = ? AND f.estado = fp.estado_asociado AND f.fecha_fin IS NOT NULL)) as completado',
                [$tramite->id],
            )
            ->orderBy('fp.orden')
            ->get()
            ->map(function (object $paso) use ($tramite): object {
                $paso->completado = (bool) $paso->completado || $tramite->estado === 'CONCLUIDO';

                return $paso;
            });
    }

    /**
     * True si el trámite estuvo alguna vez en el estado indicado (historial de tramite_tiempo_fase).
     */
    public function pasoPorEstado(int $tramiteId, string $estado): bool
    {
        return DB::table('tramite_tiempo_fase')->where('tramite_id', $tramiteId)->where('estado', $estado)->exists();
    }

    public function tieneCertificadoOficialVigente(int $tramiteId): bool
    {
        return DB::table('certificado_oficial')->where('tramite_id', $tramiteId)->where('estado', 'VIGENTE')->exists();
    }

    /**
     * Datos de la carátula del legajo: trámite, solicitante y carrera.
     */
    public function datosCaratula(int $tramiteId): ?object
    {
        return DB::table('tramite as t')
            ->join('tipo_tramite as tt', 'tt.id', '=', 't.tipo_tramite_id')
            ->join('usuario as u', 'u.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->where('t.id', $tramiteId)
            ->select('t.*', 'tt.codigo as tipo', DB::raw("u.nombres || ' ' || u.apellidos as estudiante"), 'u.ci', 'u.registro', 'c.nombre as carrera')
            ->first();
    }

    private function consultaBase(): Builder
    {
        return DB::table('tramite as t')
            ->join('tipo_tramite as tt', 'tt.id', '=', 't.tipo_tramite_id')
            ->select('t.*', 'tt.codigo as tipo', 'tt.nombre as tipo_nombre');
    }

    private function consultaConSolicitante(): Builder
    {
        return $this->consultaBase()
            ->join('usuario as est', 'est.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->join('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->leftJoin('v_semaforo_sla as sla', 'sla.tramite_id', '=', 't.id')
            ->addSelect(
                DB::raw("est.nombres || ' ' || est.apellidos as estudiante_nombre"),
                'est.ci as estudiante_ci',
                'est.registro as registro_universitario',
                'c.nombre as carrera',
                'f.nombre as facultad',
                'sla.semaforo',
                'sla.horas_consumidas',
                'sla.plazo_horas'
            );
    }
}
