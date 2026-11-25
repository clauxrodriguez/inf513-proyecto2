<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bandeja de notificaciones web de cada usuario (campanita). Los cambios de estado los notifica
 * el trigger al solicitante; las bandejas de cada rol se notifican desde la capa de negocio.
 */
class NotificacionRepository
{
    public function recientesDe(int $usuarioId, int $limite = 30): Collection
    {
        return DB::table('notificacion as n')
            ->leftJoin('tramite as t', 't.id', '=', 'n.tramite_id')
            ->where('n.usuario_id', $usuarioId)
            ->select('n.id', 'n.tramite_id', 'n.tipo', 'n.mensaje', 'n.fecha_creacion as fecha', 'n.leida', 't.codigo as tramite_codigo')
            ->orderByDesc('n.id')
            ->limit($limite)
            ->get();
    }

    public function noLeidasDe(int $usuarioId): int
    {
        return DB::table('notificacion')->where('usuario_id', $usuarioId)->where('leida', false)->count();
    }

    /**
     * Notifica a todos los usuarios activos con el rol indicado; devuelve cuántos fueron notificados.
     */
    public function notificarRol(string $rol, ?int $tramiteId, string $tipo, string $mensaje): int
    {
        return DB::table('notificacion')->insertUsing(
            ['usuario_id', 'tramite_id', 'tipo', 'mensaje'],
            DB::table('usuario as u')
                ->join('usuario_rol as ur', 'ur.usuario_id', '=', 'u.id')
                ->join('rol as r', 'r.id', '=', 'ur.rol_id')
                ->where('r.codigo', $rol)
                ->where('u.activo', true)
                ->selectRaw('DISTINCT u.id, ?::bigint, ?, ?', [$tramiteId, $tipo, mb_substr($mensaje, 0, 500)])
        );
    }

    public function notificarUsuario(int $usuarioId, ?int $tramiteId, string $tipo, string $mensaje): void
    {
        DB::table('notificacion')->insert([
            'usuario_id' => $usuarioId, 'tramite_id' => $tramiteId, 'tipo' => $tipo, 'mensaje' => mb_substr($mensaje, 0, 500),
        ]);
    }

    /**
     * Marca como leída una notificación del propio usuario; devuelve las filas afectadas.
     */
    public function marcarLeida(int $notificacionId, int $usuarioId): int
    {
        return DB::table('notificacion')->where('id', $notificacionId)->where('usuario_id', $usuarioId)->update(['leida' => true]);
    }

    public function marcarTodasLeidas(int $usuarioId): int
    {
        return DB::table('notificacion')->where('usuario_id', $usuarioId)->where('leida', false)->update(['leida' => true]);
    }
}
