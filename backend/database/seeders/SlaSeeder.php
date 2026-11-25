<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Configuración SLA (72 horas hábiles), feriados oficiales e indicadores de gestión.
 */
class SlaSeeder extends Seeder
{
    public function run(): void
    {
        $slaGeneral = DB::table('sla_config')->insertGetId([
            'tipo_tramite_id' => null,
            'plazo_horas' => 72,
            'porcentaje_alerta' => 75,
        ]);

        /** @var array<string, string> $feriados */
        $feriados = [
            '2026-01-01' => 'Año Nuevo',
            '2026-01-22' => 'Día del Estado Plurinacional',
            '2026-02-16' => 'Carnaval',
            '2026-02-17' => 'Carnaval',
            '2026-04-03' => 'Viernes Santo',
            '2026-05-01' => 'Día del Trabajo',
            '2026-06-04' => 'Corpus Christi',
            '2026-06-22' => 'Año Nuevo Andino Amazónico',
            '2026-08-06' => 'Día de la Patria',
            '2026-09-24' => 'Aniversario de Santa Cruz',
            '2026-11-02' => 'Día de Todos los Santos',
            '2026-12-25' => 'Navidad',
        ];

        foreach ($feriados as $fecha => $descripcion) {
            DB::table('feriado')->insert([
                'fecha' => $fecha,
                'sla_config_id' => $slaGeneral,
                'descripcion' => $descripcion,
            ]);
        }

        DB::table('indicador_gestion')->insert([
            ['codigo' => 'IND_CUMPLIMIENTO_SLA', 'nombre' => '% de trámites concluidos dentro de 72 h hábiles', 'unidad_medida' => '%', 'meta' => 95],
            ['codigo' => 'IND_TIEMPO_PROMEDIO', 'nombre' => 'Tiempo promedio de despacho', 'unidad_medida' => 'HORAS', 'meta' => 48],
            ['codigo' => 'IND_TRAMITES_INGRESADOS', 'nombre' => 'Trámites ingresados', 'unidad_medida' => 'CANTIDAD', 'meta' => null],
            ['codigo' => 'IND_TRAMITES_CONCLUIDOS', 'nombre' => 'Trámites concluidos', 'unidad_medida' => 'CANTIDAD', 'meta' => null],
            ['codigo' => 'IND_TASA_OBSERVACION', 'nombre' => '% de trámites con observaciones', 'unidad_medida' => '%', 'meta' => 10],
            ['codigo' => 'IND_RECAUDACION', 'nombre' => 'Recaudación por valorados', 'unidad_medida' => 'BS', 'meta' => null],
            ['codigo' => 'IND_FIRMAS_REMOTAS', 'nombre' => '% de firmas realizadas en forma remota', 'unidad_medida' => '%', 'meta' => null],
            ['codigo' => 'IND_FIRMAS_SUPLENTE', 'nombre' => 'Firmas realizadas por autoridad suplente', 'unidad_medida' => 'CANTIDAD', 'meta' => null],
        ]);
    }
}
