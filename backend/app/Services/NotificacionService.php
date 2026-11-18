<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\NotificacionRepository;
use Illuminate\Support\Collection;

/**
 * RF-19 - Notificaciones del usuario (pendientes de firma, cambios de estado, alertas SLA, etc.).
 */
class NotificacionService
{
    public function __construct(private NotificacionRepository $notificaciones) {}

    /**
     * @return array{no_leidas: int, notificaciones: Collection}
     */
    public function bandeja(int $usuarioId): array
    {
        return [
            'no_leidas' => $this->notificaciones->noLeidasDe($usuarioId),
            'notificaciones' => $this->notificaciones->recientesDe($usuarioId),
        ];
    }

    public function marcarLeida(int $notificacionId, int $usuarioId): void
    {
        if ($this->notificaciones->marcarLeida($notificacionId, $usuarioId) === 0) {
            throw ReglaNegocioException::noEncontrado('La notificación no existe.');
        }
    }

    public function marcarTodasLeidas(int $usuarioId): int
    {
        return $this->notificaciones->marcarTodasLeidas($usuarioId);
    }
}
