<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos iniciales del sistema. Instalación: php artisan migrate:fresh --seed
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AccesoSeeder::class,
            EstructuraAcademicaSeeder::class,
            CatalogoTramiteSeeder::class,
            SlaSeeder::class,
            UsuarioSeeder::class,
        ]);
    }
}
