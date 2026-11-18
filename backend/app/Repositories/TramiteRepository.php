<?php

namespace App\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trámite y sus partes: documentos, requisitos, solicitud de descuento y observaciones.
 */
class TramiteRepository
{
    public function buscar(int $id): ?object
    {
        return DB::table('tramite')->where('id', $id)->first();
    }

    /**
     * @param  array<int>  $ids
     */
    public function buscarVarios(array $ids): Collection
    {
        return DB::table('tramite')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Trámites con solicitante, carrera y semáforo SLA.
     */
    public function listar(?int $solicitanteId = null, ?string $estado = null): Collection
    {
        return $this->consultaConSolicitante()
            ->when($solicitanteId !== null, fn ($query) => $query->where('t.solicitante_id', $solicitanteId))
            ->when($estado !== null, fn ($query) => $query->where('t.estado', $estado))
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
    public function agregarDocumento(array $datos): void
    {
        DB::table('tramite_documento')->insert($datos);
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
            ->select('td.*', 'cd.codigo', 'cd.nombre as documento_nombre', 'cd.unidad_cobro', 'cd.arancel_referencial')
            ->orderBy('td.id')
            ->get()
            ->groupBy('tramite_id');
    }

    /**
     * Documentos no rechazados para la carátula del legajo.
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
     * Cantidad de documentos y si todos tienen sus firmas precedentes verificadas, por trámite.
     *
     * @param  iterable<int>  $tramiteIds
     */
    public function resumenDocumentos(iterable $tramiteIds): Collection
    {
        return DB::table('tramite_documento')
            ->whereIn('tramite_id', $tramiteIds)
            ->where('estado', '<>', 'RECHAZADO')
            ->selectRaw('tramite_id, COUNT(*) AS total_documentos, BOOL_AND(firmas_verificadas) AS firmas_verificadas')
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
            ->select('tr.*', 'r.tipo', 'r.codigo', 'r.descripcion', 'r.obligatorio', 'r.pais_aplica')
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

    // ------------------------------------------------------------------ descuento de diplomado

    /**
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
        DB::table('observacion')->where('id', $observacionId)->update(['estado' => 'SUBSANADA']);
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

    public function flujoDe(string $codigo): Collection
    {
        return DB::table('v_tramite_flujo')->where('codigo', $codigo)->get();
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
            ->join('usuario as u', 'u.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->where('t.id', $tramiteId)
            ->select('t.*', DB::raw("u.nombres || ' ' || u.apellidos as estudiante"), 'u.ci', 'u.registro', 'c.nombre as carrera')
            ->first();
    }

    // ------------------------------------------------------------------ indicadores (CU2)

    /**
     * @return array{total_tramites: int, despachados: int, nacional: int, exterior: int, descuento_diplomado: int, sla_vencidos: int, cumplimiento_sla: float|int}
     */
    public function indicadores(): array
    {
        $total = DB::table('tramite')->count();
        $despachados = DB::table('tramite')->whereIn('estado', ['FIRMADO_DIGITALMENTE', 'DERIVADO_RECTORADO', 'CONCLUIDO'])->count();
        $slaVencidos = DB::table('v_semaforo_sla')->where('semaforo', 'ROJO')->count();
        $slaTotal = DB::table('v_semaforo_sla')->count();

        return [
            'total_tramites' => $total,
            'despachados' => $despachados,
            'nacional' => DB::table('tramite')->where('destino', 'NACIONAL')->count(),
            'exterior' => DB::table('tramite')->where('destino', 'EXTERIOR')->count(),
            'descuento_diplomado' => DB::table('tramite')->where('tipo', 'DESCUENTO_DIPLOMADO')->count(),
            'sla_vencidos' => $slaVencidos,
            'cumplimiento_sla' => $slaTotal > 0 ? round((($slaTotal - $slaVencidos) / $slaTotal) * 100, 1) : 100,
        ];
    }

    private function consultaConSolicitante(): Builder
    {
        return DB::table('tramite as t')
            ->join('usuario as est', 'est.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->leftJoin('v_semaforo_sla as sla', 'sla.tramite_id', '=', 't.id')
            ->select(
                't.*',
                DB::raw("est.nombres || ' ' || est.apellidos as estudiante_nombre"),
                'est.ci as estudiante_ci',
                'est.registro as registro_universitario',
                'c.nombre as carrera',
                'sla.semaforo',
                'sla.horas_consumidas',
                'sla.plazo_horas'
            );
    }
}
