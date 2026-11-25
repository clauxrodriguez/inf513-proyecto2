<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Un usuario de prueba por rol del flujo (Origen Facultativo -> Vicerrectorado -> Rectorado).
 */
class UsuarioSeeder extends Seeder
{
    public function run(): void
    {
        $carreraSistemas = DB::table('carrera')->where('codigo', '187-3')->value('id');
        $roles = DB::table('rol')->pluck('id', 'codigo');

        /** @var list<array{nombres: string, apellidos: string, ci: string, email: string, password: string, rol: string, cargo: ?string, carrera_id: ?int, registro?: string}> $usuarios */
        $usuarios = [
            [
                'nombres' => 'Admin', 'apellidos' => 'Desarrollador UAGRM', 'ci' => '1000001',
                'email' => 'admin@uagrm.edu.bo', 'password' => 'Admin123*',
                'rol' => 'ADMIN', 'cargo' => 'ADMINISTRADOR_SISTEMA', 'carrera_id' => null,
            ],
            [
                'nombres' => 'Roberto', 'apellidos' => 'Gutiérrez', 'ci' => '1000010',
                'email' => 'carrera.sistemas@uagrm.edu.bo', 'password' => 'Carrera123*',
                'rol' => 'DIRECTOR_CARRERA', 'cargo' => 'DIRECTOR_CARRERA', 'carrera_id' => $carreraSistemas,
            ],
            [
                'nombres' => 'Juan', 'apellidos' => 'Pérez', 'ci' => '1000006',
                'email' => 'decano.ficct@uagrm.edu.bo', 'password' => 'Decano123*',
                'rol' => 'DECANO', 'cargo' => 'DECANO', 'carrera_id' => $carreraSistemas,
            ],
            [
                'nombres' => 'Patricia', 'apellidos' => 'Salazar', 'ci' => '1000011',
                'email' => 'admisiones@uagrm.edu.bo', 'password' => 'Admisiones123*',
                'rol' => 'ADMISIONES', 'cargo' => 'JEFE_ADMISIONES', 'carrera_id' => null,
            ],
            [
                'nombres' => 'Marco', 'apellidos' => 'Vaca', 'ci' => '1000012',
                'email' => 'daga@uagrm.edu.bo', 'password' => 'Daga123*',
                'rol' => 'DAGA', 'cargo' => 'JEFE_DAGA', 'carrera_id' => null,
            ],
            [
                'nombres' => 'Laura', 'apellidos' => 'Mendoza', 'ci' => '1000002',
                'email' => 'recepcion@uagrm.edu.bo', 'password' => 'Recepcion123*',
                'rol' => 'RECEPCION', 'cargo' => 'ENCARGADO_LEGALIZACIONES', 'carrera_id' => null,
            ],
            [
                'nombres' => 'Juana', 'apellidos' => 'Borja Saavedra', 'ci' => '1000003',
                'email' => 'vicerrector@uagrm.edu.bo', 'password' => 'Vice123*',
                'rol' => 'VICERRECTOR', 'cargo' => 'VICERRECTOR', 'carrera_id' => null,
            ],
            [
                'nombres' => 'Reinerio', 'apellidos' => 'Vargas', 'ci' => '1000005',
                'email' => 'rectorado@uagrm.edu.bo', 'password' => 'Rectorado123*',
                'rol' => 'RECTOR', 'cargo' => 'RECTOR', 'carrera_id' => null,
            ],
            [
                'nombres' => 'Carlos', 'apellidos' => 'Rojas', 'ci' => '7654321', 'registro' => '219012345',
                'email' => 'estudiante@uagrm.edu.bo', 'password' => 'Estudiante123*',
                'rol' => 'ESTUDIANTE', 'cargo' => null, 'carrera_id' => $carreraSistemas,
            ],
        ];

        foreach ($usuarios as $usuario) {
            $usuarioId = DB::table('usuario')->insertGetId([
                'nombres' => $usuario['nombres'],
                'apellidos' => $usuario['apellidos'],
                'ci' => $usuario['ci'],
                'registro' => $usuario['registro'] ?? null,
                'email' => $usuario['email'],
                'password_hash' => Hash::make($usuario['password']),
                'cargo' => $usuario['cargo'],
            ]);

            DB::table('usuario_rol')->insert([
                'usuario_id' => $usuarioId,
                'rol_id' => $roles[$usuario['rol']],
            ]);

            if ($usuario['carrera_id'] !== null) {
                DB::table('usuario_carrera')->insert([
                    'usuario_id' => $usuarioId,
                    'carrera_id' => $usuario['carrera_id'],
                ]);
            }
        }
    }
}
