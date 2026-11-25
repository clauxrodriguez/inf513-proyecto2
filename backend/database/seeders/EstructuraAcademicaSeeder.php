<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Las 18 facultades de la UAGRM en su orden de prelación institucional (antigüedad histórica y normativa
 * estatutaria, incluidas las facultades integrales provinciales) y las carreras de prueba.
 */
class EstructuraAcademicaSeeder extends Seeder
{
    /**
     * @var list<array{0: string, 1: string, 2: int}> sigla, nombre y año de referencia, en orden de prelación
     */
    public const FACULTADES = [
        ['FCJPSRI', 'Facultad de Ciencias Jurídicas, Políticas, Sociales y Relaciones Internacionales', 1879],
        ['FCEE', 'Facultad de Ciencias Económicas y Empresariales', 1939],
        ['FCET', 'Facultad de Ciencias Exactas y Tecnología', 1940],
        ['FCA', 'Facultad de Ciencias Agrícolas', 1940],
        ['FCV', 'Facultad de Ciencias Veterinarias', 1940],
        ['FH', 'Facultad de Humanidades', 1973],
        ['FCSH', 'Facultad de Ciencias de la Salud Humana', 1979],
        ['FCAPU', 'Facultad de Ciencias del Hábitat, Diseño y Arte', 1983],
        ['FCAF', 'Facultad de Auditoría Financiera o Contaduría Pública', 1995],
        ['FP', 'Facultad Politécnica', 1995],
        ['FINOR', 'Facultad Integral del Norte (Montero)', 2004],
        ['FICH', 'Facultad Integral del Chaco (Camiri)', 2004],
        ['FICHT', 'Facultad Integral Chiquitana (San Ignacio de Velasco)', 2006],
        ['FIV', 'Facultad Integral de los Valles Cruceños (Vallegrande)', 2008],
        ['FICCT', 'Facultad de Ingeniería en Ciencias de la Computación y Telecomunicaciones', 2011],
        ['FCF', 'Facultad de Ciencias Farmacéuticas y Bioquímicas', 2012],
        ['FII', 'Facultad Integral de Ichilo (Yapacaní)', 2012],
        ['FINI', 'Facultad Integral del Noreste (San Julián)', 2015],
    ];

    public function run(): void
    {
        DB::table('facultad')->insert(array_map(fn (array $facultad, int $indice): array => [
            'codigo' => $facultad[0],
            'nombre' => $facultad[1],
            'anio_creacion' => $facultad[2],
            'orden_prelacion' => $indice + 1,
        ], self::FACULTADES, array_keys(self::FACULTADES)));

        $facultades = DB::table('facultad')->pluck('id', 'codigo');

        /** @var list<array{0: string, 1: string, 2: string, 3: string}> $carreras facultad, código, nombre, régimen */
        $carreras = [
            ['FCJPSRI', 'DER', 'Derecho', 'ANUAL'],
            ['FCAF', 'CPA', 'Auditoría / Contaduría Pública', 'SEMESTRAL'],
            ['FCEE', 'ICOM', 'Ingeniería Comercial', 'SEMESTRAL'],
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
