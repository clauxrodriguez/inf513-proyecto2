<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogos: carreras, documentos con su cadena de firmas precedentes, requisitos y guía procesal.
 */
class CatalogoRepository
{
    public function carreras(): Collection
    {
        return DB::table('carrera as c')
            ->join('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->select('c.id', 'c.codigo', 'c.nombre', 'f.nombre as facultad')
            ->orderBy('f.nombre')
            ->orderBy('c.nombre')
            ->get();
    }

    public function documentosActivos(): Collection
    {
        return DB::table('catalogo_documento as cd')
            ->join('unidad_origen as uo', 'uo.id', '=', 'cd.unidad_emisora_id')
            ->where('cd.activo', true)
            ->select('cd.*', 'uo.nombre as unidad_emisora')
            ->orderBy('cd.nombre')
            ->get();
    }

    /**
     * Firmas y sellos precedentes (RN-04) agrupados por id de documento del catálogo.
     *
     * @param  iterable<int>|null  $documentoIds  null = todos
     */
    public function firmasPrecedentes(?iterable $documentoIds = null): Collection
    {
        return DB::table('catalogo_firma_precedente as fp')
            ->join('unidad_origen as u', 'u.id', '=', 'fp.unidad_id')
            ->when($documentoIds !== null, fn ($query) => $query->whereIn('fp.catalogo_documento_id', $documentoIds))
            ->select('fp.catalogo_documento_id', 'fp.orden', 'u.nombre as unidad', 'fp.cargo_firmante', 'fp.requiere_sello')
            ->orderBy('fp.orden')
            ->get()
            ->groupBy('catalogo_documento_id');
    }

    public function requisitos(): Collection
    {
        return DB::table('requisito')->orderBy('id')->get();
    }

    public function requisitosPorTipo(): Collection
    {
        return DB::table('requisito')->orderBy('tipo')->orderBy('id')->get();
    }

    public function guiaProcesal(): Collection
    {
        return DB::table('v_guia_procesal')->get();
    }

    public function rutaDocumentos(): Collection
    {
        return DB::table('v_ruta_documento')->get();
    }
}
