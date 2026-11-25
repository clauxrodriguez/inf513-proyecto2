<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\CatalogoRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\SlaRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;

/**
 * CU7 - Monitoreo del SLA de 72 horas hábiles, alertas tempranas y parametrización de umbrales.
 * El cómputo (horas hábiles, feriados, pausa en OBSERVADO, semáforo) lo hace PostgreSQL en v_semaforo_sla.
 */
class SlaService
{
    public const TIPOS_TRAMITE = ['LEGALIZACION', 'DESCUENTO_DIPLOMADO'];

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private SlaRepository $sla,
        private TramiteRepository $tramites,
        private CatalogoRepository $catalogos,
        private NotificacionRepository $notificaciones,
    ) {}

    /**
     * Genera las alertas nuevas y notifica a la Ventanilla (comando programado o al abrir el monitor).
     */
    public function verificarAlertas(): int
    {
        return $this->transaccion->ejecutar(function (): int {
            $nuevas = $this->sla->generarAlertas();

            foreach ($nuevas as $alerta) {
                $mensaje = $alerta->tipo === 'VENCIDA'
                    ? "SLA VENCIDO: el trámite {$alerta->codigo} superó las 72 horas hábiles."
                    : "Alerta preventiva: el trámite {$alerta->codigo} consumió el umbral de su plazo SLA.";
                $this->notificaciones->notificarRol('RECEPCION', $alerta->tramite_id, 'ALERTA_SLA', $mensaje);
            }

            return $nuevas->count();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function monitor(bool $incluirAtendidas): array
    {
        $nuevas = $this->verificarAlertas();

        // El resumen del semáforo (conteos y % en plazo) se muestra solo en el Dashboard
        return [
            'alertas_nuevas' => $nuevas,
            'tramites' => $this->sla->tramitesEnCurso()->map(function (object $tramite): object {
                $tramite->estado_nombre = SeguimientoService::ESTADOS_LEGIBLES[$tramite->estado] ?? $tramite->estado;
                $tramite->reloj_activo = ! in_array($tramite->estado, SlaRepository::ESTADOS_SIN_RELOJ, true);

                return $tramite;
            }),
            'alertas' => $this->sla->alertas($incluirAtendidas),
            'configuracion' => $this->configuracion(),
        ];
    }

    /**
     * Desglose de horas hábiles por fase y cuánto de ello cuenta para el SLA.
     *
     * @return array<string, mixed>
     */
    public function desglose(int $tramiteId): array
    {
        $tramite = $this->tramites->buscar($tramiteId) ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        $fases = $this->sla->fasesDe($tramiteId)->map(function (object $fase): object {
            $fase->estado_nombre = SeguimientoService::ESTADOS_LEGIBLES[$fase->estado] ?? $fase->estado;
            $fase->cuenta_sla = ! in_array($fase->estado, SlaRepository::ESTADOS_SIN_RELOJ, true);

            return $fase;
        });

        $porEstado = $fases->groupBy('estado')->map(fn ($grupo, $estado) => [
            'estado' => $estado,
            'estado_nombre' => $grupo->first()->estado_nombre,
            'horas_habiles' => round($grupo->sum(fn ($fase) => (float) $fase->horas_habiles), 2),
            'cuenta_sla' => $grupo->first()->cuenta_sla,
            'veces' => $grupo->count(),
        ])->values();

        return [
            'tramite' => ['id' => $tramite->id, 'codigo' => $tramite->codigo, 'estado' => $tramite->estado, 'tipo' => $tramite->tipo],
            'semaforo' => $this->sla->semaforoDe($tramiteId),
            'fases' => $fases,
            'por_estado' => $porEstado,
            'horas_en_sla' => round($fases->where('cuenta_sla', true)->sum(fn ($fase) => (float) $fase->horas_habiles), 2),
            'horas_detenidas' => round($fases->where('cuenta_sla', false)->sum(fn ($fase) => (float) $fase->horas_habiles), 2),
        ];
    }

    public function atenderAlerta(int $alertaId, int $usuarioId): void
    {
        $alerta = $this->sla->buscarAlerta($alertaId) ?? throw ReglaNegocioException::noEncontrado('La alerta no existe.');
        if ($alerta->atendida) {
            throw new ReglaNegocioException('La alerta ya fue atendida.');
        }

        $this->sla->marcarAtendida($alertaId, $usuarioId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function configuracion(): array
    {
        return $this->sla->configuracion()->map(fn (object $fila) => [
            'id' => $fila->id,
            'tipo' => $fila->tipo,
            'plazo_horas' => (float) $fila->plazo_horas,
            'porcentaje_alerta' => (float) $fila->porcentaje_alerta,
            'umbral_alerta_horas' => round((float) $fila->plazo_horas * (float) $fila->porcentaje_alerta / 100, 2),
        ])->values()->all();
    }

    public function guardarConfiguracion(?string $tipo, float $plazoHoras, float $porcentajeAlerta): void
    {
        $this->sla->guardarConfiguracion($tipo ? $this->catalogos->tipoTramiteId($tipo) : null, $plazoHoras, $porcentajeAlerta);
    }

    public function eliminarConfiguracion(string $tipo): void
    {
        if ($this->sla->eliminarConfiguracion((int) $this->catalogos->tipoTramiteId($tipo)) === 0) {
            throw ReglaNegocioException::noEncontrado("No existe una configuración específica para {$tipo}.");
        }
    }
}
