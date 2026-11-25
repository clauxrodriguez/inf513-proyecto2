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
    /**
     * Indicadores de gestión: su valor se calcula en cada consulta a partir de los trámites, el SLA y las firmas.
     * menor_es_mejor indica que la meta es un máximo (p. ej. horas promedio), no un mínimo.
     *
     * @var array<string, array{nombre: string, unidad_medida: string, meta: ?float, menor_es_mejor: bool}>
     */
    private const INDICADORES = [
        'IND_CUMPLIMIENTO_SLA' => ['nombre' => '% de trámites concluidos dentro de 72 h hábiles', 'unidad_medida' => '%', 'meta' => 95.0, 'menor_es_mejor' => false],
        'IND_TIEMPO_PROMEDIO' => ['nombre' => 'Tiempo promedio de despacho', 'unidad_medida' => 'HORAS', 'meta' => 48.0, 'menor_es_mejor' => true],
        'IND_TRAMITES_INGRESADOS' => ['nombre' => 'Trámites ingresados', 'unidad_medida' => 'CANTIDAD', 'meta' => null, 'menor_es_mejor' => false],
        'IND_TRAMITES_CONCLUIDOS' => ['nombre' => 'Trámites concluidos', 'unidad_medida' => 'CANTIDAD', 'meta' => null, 'menor_es_mejor' => false],
        'IND_TASA_OBSERVACION' => ['nombre' => '% de trámites con observaciones', 'unidad_medida' => '%', 'meta' => 10.0, 'menor_es_mejor' => true],
        'IND_RECAUDACION' => ['nombre' => 'Recaudación por valorados', 'unidad_medida' => 'BS', 'meta' => null, 'menor_es_mejor' => false],
        'IND_FIRMAS_REMOTAS' => ['nombre' => '% de firmas realizadas en forma remota', 'unidad_medida' => '%', 'meta' => null, 'menor_es_mejor' => false],
        'IND_FIRMAS_SUPLENTE' => ['nombre' => 'Firmas realizadas por autoridad suplente', 'unidad_medida' => 'CANTIDAD', 'meta' => null, 'menor_es_mejor' => false],
    ];

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
     * Valor actual de cada indicador de gestión comparado con su meta.
     *
     * @param  array<string, int|float>  $totales
     * @param  array<string, int|float|null>  $sla
     * @return Collection<int, array{codigo: string, nombre: string, unidad_medida: string, meta: ?float, valor: int|float|null, cumple: ?bool}>
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

        return collect(self::INDICADORES)->map(function (array $indicador, string $codigo) use ($valores): array {
            $valor = $valores[$codigo];
            $cumple = $indicador['meta'] === null || $valor === null ? null
                : ($indicador['menor_es_mejor'] ? $valor <= $indicador['meta'] : $valor >= $indicador['meta']);

            return [
                'codigo' => $codigo,
                'nombre' => $indicador['nombre'],
                'unidad_medida' => $indicador['unidad_medida'],
                'meta' => $indicador['meta'],
                'valor' => $valor,
                'cumple' => $cumple,
            ];
        })->values();
    }
}
