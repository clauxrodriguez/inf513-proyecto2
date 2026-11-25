<?php

namespace App\Services;

use App\Repositories\AccesoRepository;
use App\Repositories\AuditoriaRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * CU4 - Bitácora inalterable (verificación de la cadena de hash) y contexto de auditoría de cada transacción.
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
     * Identifica ante PostgreSQL al usuario de la transacción en curso; debe llamarse dentro de ella.
     */
    public function establecerContexto(int $usuarioId): void
    {
        $this->accesos->establecerContextoAuditoria(
            $usuarioId,
            rtrim((string) config('app.frontend_url'), '/').'/validar-certificado',
        );
    }
}
