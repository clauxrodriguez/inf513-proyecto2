<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\EstadisticasRepository;
use App\Repositories\SlaRepository;
use App\Repositories\TramiteRepository;
use Illuminate\Support\Collection;

/**
 * Dashboard analítico y reportes: actividad del negocio, indicadores de gestión con su meta y tráfico web.
 */
class EstadisticasService
{
    public function __construct(
        private EstadisticasRepository $estadisticas,
        private SlaRepository $sla,
        private TramiteRepository $tramites,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reporte(?string $desde, ?string $hasta): array
    {
        $desdeAccesos = $desde ?? now()->subDays(13)->toDateString();
        $hastaAccesos = $hasta ?? now()->toDateString();
        $totales = $this->estadisticas->totales($desde, $hasta);
        $sla = $this->sla->resumen();

        return [
            'periodo' => ['desde' => $desde, 'hasta' => $hasta, 'accesos_desde' => $desdeAccesos, 'accesos_hasta' => $hastaAccesos],
            'negocio' => [
                'totales' => $totales,
                'sla' => $sla,
                'por_estado' => $this->estadisticas->tramitesPorEstado($desde, $hasta),
                'por_carrera' => $this->estadisticas->tramitesPorCarrera($desde, $hasta),
                'por_tipo' => $this->estadisticas->tramitesPorTipoYDestino($desde, $hasta),
                'recaudacion' => $this->estadisticas->recaudacionMensual($desde, $hasta),
                'indicadores' => $this->indicadores($totales, $sla),
            ],
            'accesos' => [
                'totales' => $this->estadisticas->totalesAcceso($desdeAccesos, $hastaAccesos),
                'por_dia' => $this->estadisticas->accesosPorDia($desdeAccesos, $hastaAccesos),
                'recursos' => $this->estadisticas->recursosMasAccedidos($desdeAccesos, $hastaAccesos),
                'paginas' => $this->estadisticas->paginasMasVisitadas(),
                'ips_fallidos' => $this->estadisticas->ipsConMasFallidos($desdeAccesos, $hastaAccesos),
            ],
        ];
    }

    /**
     * Resumen del inicio: el estudiante ve sus trámites; el personal, el estado general y el semáforo.
     *
     * @return array<string, mixed>
     */
    public function resumen(User $usuario): array
    {
        $esEstudiante = $usuario->rol === 'ESTUDIANTE';

        return [
            'por_estado' => $this->estadisticas->tramitesPorEstado(null, null, $esEstudiante ? $usuario->id : null),
            'sla' => $esEstudiante ? null : $this->sla->resumen(),
        ];
    }

    /**
     * Valor actual de cada indicador de gestión (indicador_gestion) comparado con su meta.
     *
     * @param  array<string, int|float>  $totales
     * @param  array<string, int|float|null>  $sla
     */
    private function indicadores(array $totales, array $sla): Collection
    {
        $porcentaje = fn (int|float $parte, int|float $total): ?float => $total > 0 ? round($parte * 100 / $total, 1) : null;

        $valores = [
            'IND_CUMPLIMIENTO_SLA' => $porcentaje($sla['concluidos_en_plazo'], $sla['concluidos']),
            'IND_TIEMPO_PROMEDIO' => $sla['promedio_horas_concluidos'],
            'IND_TRAMITES_INGRESADOS' => $totales['ingresados'],
            'IND_TRAMITES_CONCLUIDOS' => $totales['concluidos'],
            'IND_TASA_OBSERVACION' => $porcentaje($totales['observados'], $totales['ingresados']),
            'IND_RECAUDACION' => $totales['recaudado'],
            'IND_FIRMAS_REMOTAS' => $porcentaje($totales['firmas_remotas'], $totales['firmas_despacho']),
            'IND_FIRMAS_SUPLENTE' => $totales['firmas_suplente'],
        ];
        // Indicadores en los que un valor menor es mejor
        $menorEsMejor = ['IND_TIEMPO_PROMEDIO', 'IND_TASA_OBSERVACION'];

        return $this->estadisticas->indicadoresDeGestion()->map(function (object $indicador) use ($valores, $menorEsMejor): object {
            $indicador->valor = $valores[$indicador->codigo] ?? null;
            $indicador->cumple = $indicador->meta === null || $indicador->valor === null ? null
                : (in_array($indicador->codigo, $menorEsMejor, true)
                    ? $indicador->valor <= (float) $indicador->meta
                    : $indicador->valor >= (float) $indicador->meta);

            return $indicador;
        });
    }
}
