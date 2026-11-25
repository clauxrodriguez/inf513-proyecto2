<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Facultades y carreras.
 */
class EstructuraAcademicaSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('facultad')->insert([
            ['codigo' => 'FCJPS', 'nombre' => 'Facultad de Ciencias Jurídicas, Políticas, Sociales y Relaciones Internacionales', 'anio_creacion' => 1879, 'orden_prelacion' => 1],
            ['codigo' => 'FCE', 'nombre' => 'Facultad de Ciencias Económicas y Empresariales', 'anio_creacion' => 1939, 'orden_prelacion' => 2],
            ['codigo' => 'FCSH', 'nombre' => 'Facultad de Ciencias de la Salud Humana', 'anio_creacion' => 1978, 'orden_prelacion' => 3],
            ['codigo' => 'FICCT', 'nombre' => 'Facultad de Ingeniería en Ciencias de la Computación y Telecomunicaciones', 'anio_creacion' => 2012, 'orden_prelacion' => 4],
        ]);

        $facultades = DB::table('facultad')->pluck('id', 'codigo');

        /** @var list<array{0: string, 1: string, 2: string, 3: string}> $carreras facultad, código, nombre, régimen */
        $carreras = [
            ['FCJPS', 'DER', 'Derecho', 'ANUAL'],
            ['FCE', 'CPA', 'Auditoría / Contaduría Pública', 'SEMESTRAL'],
            ['FCE', 'ICOM', 'Ingeniería Comercial', 'SEMESTRAL'],
            ['FCSH', 'MED', 'Medicina', 'ANUAL'],
            ['FICCT', '187-3', 'Ingeniería de Sistemas', 'SEMESTRAL'],
            ['FICCT', '187-4', 'Ingeniería Informática', 'SEMESTRAL'],
        ];

        DB::table('carrera')->insert(array_map(fn (array $carrera): array => [
            'facultad_id' => $facultades[$carrera[0]],
            'codigo' => $carrera[1],
            'nombre' => $carrera[2],
            'regimen' => $carrera[3],
        ], $carreras));
    }
}
