<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Delegaciones de autoridad (CU2) y certificados digitales de los firmantes.
 */
class DelegacionRepository
{
    public function listar(): Collection
    {
        return DB::table('delegacion_autoridad as d')
            ->join('usuario as tit', 'tit.id', '=', 'd.titular_id')
            ->join('usuario as sup', 'sup.id', '=', 'd.suplente_id')
            ->leftJoin('carrera as c', 'c.id', '=', 'sup.carrera_id')
            ->leftJoin('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->select(
                'd.*',
                DB::raw("tit.nombres || ' ' || tit.apellidos as titular_nombre"),
                DB::raw("sup.nombres || ' ' || sup.apellidos as suplente_nombre"),
                'f.nombre as facultad_suplente',
                'f.orden_prelacion'
            )
            ->orderByDesc('d.id')
            ->get();
    }

    public function buscar(int $id): ?object
    {
        return DB::table('delegacion_autoridad')->where('id', $id)->first();
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(array $datos): int
    {
        return DB::table('delegacion_autoridad')->insertGetId($datos);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizar(int $id, array $datos): int
    {
        return DB::table('delegacion_autoridad')->where('id', $id)->update($datos);
    }

    /**
     * Delegación ACTIVA del suplente que cubre la fecha actual (misma condición que trg_lote_validar).
     */
    public function vigenteDelSuplente(int $suplenteId): ?object
    {
        return DB::table('delegacion_autoridad as d')
            ->join('usuario as t', 't.id', '=', 'd.titular_id')
            ->where('d.suplente_id', $suplenteId)
            ->where('d.estado', 'ACTIVA')
            ->whereRaw('CURRENT_DATE BETWEEN d.fecha_inicio AND d.fecha_fin')
            ->orderByDesc('d.id')
            ->select('d.id', 'd.tipo_resolucion', 'd.nro_resolucion', 'd.fecha_resolucion', 'd.fecha_inicio', 'd.fecha_fin', 'd.motivo',
                DB::raw("t.nombres || ' ' || t.apellidos as titular"))
            ->first();
    }

    public function listarCertificados(): Collection
    {
        return DB::table('certificado_digital as cd')
            ->join('usuario as u', 'u.id', '=', 'cd.usuario_id')
            ->select('cd.*', DB::raw("u.nombres || ' ' || u.apellidos as usuario_nombre"), 'u.rol')
            ->orderByDesc('cd.id')
            ->get();
    }

    public function certificadoVigente(int $usuarioId): ?object
    {
        return DB::table('certificado_digital')
            ->where('usuario_id', $usuarioId)
            ->where('activo', true)
            ->whereRaw('CURRENT_DATE BETWEEN fecha_emision AND fecha_expiracion')
            ->orderByDesc('id')
            ->select('id', 'numero_serie', 'emisor', 'fecha_emision', 'fecha_expiracion')
            ->first();
    }

    /**
     * Desactiva los certificados anteriores del usuario y registra el nuevo como activo.
     *
     * @param  array{usuario_id: int, numero_serie: string, emisor: string, fecha_emision: string, fecha_expiracion: string}  $certificado
     */
    public function reemplazarCertificado(array $certificado): void
    {
        DB::table('certificado_digital')->where('usuario_id', $certificado['usuario_id'])->update(['activo' => false]);
        DB::table('certificado_digital')->insert([...$certificado, 'activo' => true]);
    }
}
