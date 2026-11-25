<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Configuración SLA (72 horas hábiles), y feriados oficiales.
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
    }
}
