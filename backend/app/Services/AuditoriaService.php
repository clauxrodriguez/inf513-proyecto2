<?php

namespace App\Services;

use App\Repositories\AccesoRepository;
use App\Repositories\AuditoriaRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * CU4 - Bitácora inalterable (verificación de la cadena de hash) y usuario de auditoría de cada petición.
 */
class AuditoriaService
{
    public function __construct(
        private AuditoriaRepository $auditoria,
        private AccesoRepository $accesos,
    ) {}

    /**
     * @return array{integridad: bool, primer_id_alterado: ?int, registros: LengthAwarePaginator, accesos: Collection}
     */
    public function bitacora(?int $tramiteId, int $porPagina): array
    {
        $primerAlterado = $this->auditoria->primerRegistroAlterado();

        return [
            'integridad' => $primerAlterado === null,
            'primer_id_alterado' => $primerAlterado,
            'registros' => $this->auditoria->paginarBitacora($tramiteId, $porPagina),
            'accesos' => $this->auditoria->accesosRecientes(),
        ];
    }

    /**
     * Los triggers de PostgreSQL registran en la bitácora al usuario autenticado de la petición.
     */
    public function establecerUsuarioActual(int $usuarioId): void
    {
        $this->accesos->establecerUsuarioAuditoria($usuarioId);
    }
}
