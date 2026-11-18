<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RF-19 - Bandeja de notificaciones de cada usuario (las generan los triggers de PostgreSQL).
 */
class NotificacionRepository
{
    public function recientesDe(int $usuarioId, int $limite = 30): Collection
    {
        return DB::table('notificacion as n')
            ->leftJoin('tramite as t', 't.id', '=', 'n.tramite_id')
            ->where('n.usuario_id', $usuarioId)
            ->select('n.id', 'n.tramite_id', 'n.tipo', 'n.mensaje', 'n.fecha', 'n.leida', 't.codigo as tramite_codigo')
            ->orderByDesc('n.id')
            ->limit($limite)
            ->get();
    }

    public function noLeidasDe(int $usuarioId): int
    {
        return DB::table('notificacion')->where('usuario_id', $usuarioId)->where('leida', false)->count();
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
