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
            ->leftJoin('usuario as reg', 'reg.id', '=', 'd.registrado_por')
            ->select(
                'd.*',
                DB::raw("tit.nombres || ' ' || tit.apellidos as titular_nombre"),
                DB::raw("sup.nombres || ' ' || sup.apellidos as suplente_nombre"),
                DB::raw("reg.nombres || ' ' || reg.apellidos as registrado_por_nombre"),
                DB::raw('(SELECT f.nombre FROM usuario_carrera uc JOIN carrera c ON c.id = uc.carrera_id JOIN facultad f ON f.id = c.facultad_id
                          WHERE uc.usuario_id = d.suplente_id ORDER BY f.orden_prelacion LIMIT 1) as facultad_suplente'),
                DB::raw("(d.estado = 'ACTIVA' AND CURRENT_DATE BETWEEN d.fecha_inicio AND d.fecha_fin) as vigente_hoy")
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
     * True si el titular ya tiene una delegación ACTIVA que se superpone con el periodo indicado.
     */
    public function haySuperpuesta(int $titularId, string $inicio, string $fin): bool
    {
        return DB::table('delegacion_autoridad')
            ->where('titular_id', $titularId)
            ->where('estado', 'ACTIVA')
            ->where('fecha_inicio', '<=', $fin)
            ->where('fecha_fin', '>=', $inicio)
            ->exists();
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
            ->orderByDesc('d.fecha_registro')
            ->select('d.id', 'd.nro_resolucion', 'd.fecha_resolucion', 'd.fecha_inicio', 'd.fecha_fin', 'd.motivo',
                DB::raw("t.nombres || ' ' || t.apellidos as titular"))
            ->first();
    }

    public function listarCertificados(): Collection
    {
        return DB::table('certificado_digital as cd')
            ->join('usuario as u', 'u.id', '=', 'cd.usuario_id')
            ->select(
                'cd.*', DB::raw("u.nombres || ' ' || u.apellidos as usuario_nombre"),
                DB::raw('(SELECT string_agg(r.codigo, \', \' ORDER BY r.id) FROM usuario_rol ur JOIN rol r ON r.id = ur.rol_id WHERE ur.usuario_id = u.id) as roles'),
                DB::raw('(cd.activo AND CURRENT_DATE BETWEEN cd.fecha_emision AND cd.fecha_expiracion) as vigente')
            )
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
