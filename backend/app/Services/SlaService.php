<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\SlaRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;

/**
 * CU7 - Monitoreo del SLA de 72 horas hábiles, alertas tempranas y parametrización de umbrales.
 * El cómputo (horas hábiles, exclusión de OBSERVADO, semáforo) lo hace PostgreSQL en v_semaforo_sla.
 */
class SlaService
{
    public const TIPOS_TRAMITE = ['LEGALIZACION', 'DESCUENTO_DIPLOMADO'];

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private SlaRepository $sla,
        private TramiteRepository $tramites,
    ) {}

    /**
     * Verificación periódica (comando programado) o al consultar el monitor: genera alertas nuevas.
     */
    public function verificarAlertas(): int
    {
        return $this->sla->generarAlertas();
    }

    /**
     * Monitor SLA: genera las alertas pendientes y devuelve semáforo, resumen, alertas y configuración.
     *
     * @return array<string, mixed>
     */
    public function monitor(bool $incluirAtendidas): array
    {
        $nuevas = $this->verificarAlertas();
        $resumen = $this->sla->resumen();
        $resumen['cumplimiento'] = $resumen['concluidos'] > 0
            ? round($resumen['concluidos_en_plazo'] * 100 / $resumen['concluidos'], 1)
            : null;

        return [
            'alertas_nuevas' => $nuevas,
            'resumen' => $resumen,
            'tramites' => $this->sla->tramitesEnCurso(),
            'alertas' => $this->sla->alertas($incluirAtendidas),
            'configuracion' => $this->configuracion(),
        ];
    }

    /**
     * SF-1: desglose de horas hábiles por fase y cuánto de ello cuenta para el SLA.
     *
     * @return array<string, mixed>
     */
    public function desglose(int $tramiteId): array
    {
        $tramite = $this->tramites->buscar($tramiteId) ?? throw ReglaNegocioException::noEncontrado('El trámite no existe.');
        $fases = $this->sla->fasesDe($tramiteId);

        $porEstado = $fases->groupBy('estado')->map(fn ($grupo, $estado) => [
            'estado' => $estado,
            'estado_nombre' => $grupo->first()->estado_nombre,
            'horas_habiles' => round($grupo->sum(fn ($fase) => (float) $fase->horas_habiles), 2),
            'cuenta_sla' => (bool) $grupo->first()->cuenta_sla,
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

    /**
     * SF-2: el operador marca la alerta como atendida tras gestionar el cuello de botella.
     */
    public function atenderAlerta(int $alertaId, int $usuarioId): void
    {
        $alerta = $this->sla->buscarAlerta($alertaId) ?? throw ReglaNegocioException::noEncontrado('La alerta no existe.');
        if ($alerta->atendida) {
            throw new ReglaNegocioException('La alerta ya fue atendida.');
        }

        $this->sla->marcarAtendida($alertaId, $usuarioId);
    }

    /**
     * Configuración vigente: general y, si existe, específica por tipo (con su umbral en horas).
     *
     * @return array<int, array<string, mixed>>
     */
    public function configuracion(): array
    {
        return $this->sla->configuracion()->map(fn ($fila) => [
            'id' => $fila->id,
            'tipo' => $fila->tipo,
            'plazo_horas' => (float) $fila->plazo_horas,
            'porcentaje_alerta' => (float) $fila->porcentaje_alerta,
            'umbral_alerta_horas' => round((float) $fila->plazo_horas * (float) $fila->porcentaje_alerta / 100, 2),
        ])->values()->all();
    }

    /**
     * SF-3: ajusta el plazo y el umbral preventivo, general (tipo null) o por tipo de trámite.
     */
    public function guardarConfiguracion(?string $tipo, float $plazoHoras, float $porcentajeAlerta): void
    {
        $this->transaccion->ejecutar(fn () => $this->sla->guardarConfiguracion($tipo, $plazoHoras, $porcentajeAlerta));
    }

    /**
     * SF-3: elimina la configuración específica de un tipo; vuelve a aplicarse la general.
     */
    public function eliminarConfiguracion(string $tipo): void
    {
        if ($this->sla->eliminarConfiguracion($tipo) === 0) {
            throw ReglaNegocioException::noEncontrado("No existe una configuración específica para {$tipo}.");
        }
    }
}
