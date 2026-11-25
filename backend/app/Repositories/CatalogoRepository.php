<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogos: tipos de trámite, carreras, documentos con su cadena de firmas en origen, requisitos y guía procesal.
 */
class CatalogoRepository
{
    public function tipoTramiteId(string $codigo): ?int
    {
        return DB::table('tipo_tramite')->where('codigo', $codigo)->value('id');
    }

    public function carreras(): Collection
    {
        return DB::table('carrera as c')
            ->join('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->select('c.id', 'c.codigo', 'c.nombre', 'c.regimen', 'f.nombre as facultad')
            ->orderBy('f.nombre')
            ->orderBy('c.nombre')
            ->get();
    }

    /**
     * Carreras del usuario (usuario_carrera) con su facultad.
     */
    public function carrerasDeUsuario(int $usuarioId): Collection
    {
        return DB::table('usuario_carrera as uc')
            ->join('carrera as c', 'c.id', '=', 'uc.carrera_id')
            ->join('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->where('uc.usuario_id', $usuarioId)
            ->select('c.id', 'c.codigo', 'c.nombre', 'c.regimen', 'c.facultad_id', 'f.nombre as facultad')
            ->orderBy('c.nombre')
            ->get();
    }

    public function documentosActivos(): Collection
    {
        return DB::table('catalogo_documento as cd')
            ->join('unidad_origen as uo', 'uo.id', '=', 'cd.unidad_emisora_id')
            ->where('cd.activo', true)
            ->select('cd.id', 'cd.codigo', 'cd.nombre', 'cd.tipo_cobro', 'cd.tarifa', 'uo.nombre as unidad_emisora')
            ->orderBy('cd.nombre')
            ->get();
    }

    /**
     * Cadena de firmas en origen (catalogo_firma_orden) agrupada por documento del catálogo.
     *
     * @param  iterable<int>|null  $documentoIds  null = todos
     */
    public function firmasPrecedentes(?iterable $documentoIds = null): Collection
    {
        return DB::table('catalogo_firma_orden as fo')
            ->join('unidad_origen as u', 'u.id', '=', 'fo.unidad_origen_id')
            ->when($documentoIds !== null, fn ($query) => $query->whereIn('fo.catalogo_documento_id', $documentoIds))
            ->select('fo.catalogo_documento_id', 'fo.orden', 'u.codigo as unidad_codigo', 'u.nombre as unidad', 'fo.cargo_firmante', 'fo.requiere_sello')
            ->orderBy('fo.orden')
            ->get()
            ->groupBy('catalogo_documento_id');
    }

    public function requisitos(): Collection
    {
        return DB::table('requisito as r')
            ->join('tipo_tramite as tt', 'tt.id', '=', 'r.tipo_tramite_id')
            ->select('r.id', 'r.codigo', 'r.descripcion', 'r.obligatorio', 'tt.codigo as tipo')
            ->orderBy('tt.codigo')
            ->orderBy('r.id')
            ->get();
    }

    /**
     * Pasos de la guía procesal por tipo de trámite y destino.
     */
    public function guiaProcesal(): Collection
    {
        return DB::table('flujo_paso as fp')
            ->join('tipo_tramite as tt', 'tt.id', '=', 'fp.tipo_tramite_id')
            ->join('unidad_origen as u', 'u.id', '=', 'fp.unidad_id')
            ->select(
                'tt.codigo as tipo', DB::raw("COALESCE(fp.destino, 'TODOS') as destino"), 'fp.orden', 'u.nombre as unidad',
                'fp.nombre as paso', 'fp.descripcion', 'fp.firma_requerida', 'fp.sello_requerido', 'fp.estado_asociado'
            )
            ->orderBy('tt.codigo')
            ->orderBy('fp.orden')
            ->get();
    }
}
