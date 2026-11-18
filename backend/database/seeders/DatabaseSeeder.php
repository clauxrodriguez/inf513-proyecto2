<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Usuarios de prueba, uno por rol. Los catálogos (facultades, carreras, documentos, requisitos,
 * flujo, menús y SLA) los carga database/sql/legalizaciones.sql.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $carreraSistemas = DB::table('carrera')->where('codigo', '187-3')->value('id');
        $carreraDerecho = DB::table('carrera')->where('codigo', 'DER')->value('id');

        $usuarios = [
            [
                'nombres' => 'Admin', 'apellidos' => 'Desarrollador UAGRM', 'ci' => '1000001',
                'email' => 'admin@uagrm.edu.bo', 'password' => 'Admin123*',
                'rol' => 'ADMINISTRADOR', 'cargo' => 'ENCARGADO_LEGALIZACIONES',
            ],
            [
                'nombres' => 'Laura', 'apellidos' => 'Mendoza', 'ci' => '1000002',
                'email' => 'recepcion@uagrm.edu.bo', 'password' => 'Recepcion123*',
                'rol' => 'RECEPCION', 'cargo' => 'FUNCIONARIO_VENTANILLA',
            ],
            [
                'nombres' => 'Juana', 'apellidos' => 'Borja Saavedra', 'ci' => '1000003',
                'email' => 'vicerrector@uagrm.edu.bo', 'password' => 'Vice123*',
                'rol' => 'VICERRECTOR', 'cargo' => 'VICERRECTOR',
            ],
            [
                'nombres' => 'Carlos', 'apellidos' => 'Rojas', 'ci' => '7654321', 'registro' => '219012345',
                'email' => 'estudiante@uagrm.edu.bo', 'password' => 'Estudiante123*',
                'rol' => 'ESTUDIANTE', 'cargo' => null, 'carrera_id' => $carreraSistemas,
            ],
            [
                'nombres' => 'Reinerio', 'apellidos' => 'Vargas', 'ci' => '1000005',
                'email' => 'rectorado@uagrm.edu.bo', 'password' => 'Rectorado123*',
                'rol' => 'RECTORADO', 'cargo' => 'RECTOR',
            ],
            [
                // Decano de la facultad con mejor orden de prelación (Art. 46): suplente estatutario
                'nombres' => 'Juan', 'apellidos' => 'Perez', 'ci' => '1000006',
                'email' => 'suplente@uagrm.edu.bo', 'password' => 'Suplente123*',
                'rol' => 'SUPLENTE', 'cargo' => 'DECANO', 'carrera_id' => $carreraDerecho,
            ],
        ];

        foreach ($usuarios as $usuario) {
            User::updateOrCreate(['email' => $usuario['email']], [...$usuario, 'activo' => true]);
        }
    }
}
