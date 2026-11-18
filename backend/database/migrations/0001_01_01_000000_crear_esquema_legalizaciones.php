<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea el esquema completo de PostgreSQL (tablas, ENUM, funciones, triggers, vistas y catálogos)
 * a partir de database/sql/legalizaciones.sql. La lógica de integridad vive en PostgreSQL, por eso
 * el esquema se mantiene en un único script SQL en lugar de migraciones con el Schema Builder.
 *
 * Instalación nueva:      php artisan migrate --seed
 * Reinstalar desde cero:  php artisan migrate:fresh --seed
 */
return new class extends Migration
{
    public function up(): void
    {
        // Protección: si la base ya tiene el esquema (p. ej. cargado antes con psql) no se toca.
        if (Schema::hasTable('usuario')) {
            return;
        }

        // Restos de una instalación anterior (migrate:fresh solo elimina tablas): tipos, funciones y secuencias.
        $this->eliminarObjetosDelEsquema();
        DB::unprepared(file_get_contents(database_path('sql/legalizaciones.sql')));
    }

    public function down(): void
    {
        $this->eliminarObjetosDelEsquema();
    }

    /**
     * Elimina todos los objetos del esquema public excepto la tabla de control de migraciones.
     */
    private function eliminarObjetosDelEsquema(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE r RECORD;
            BEGIN
                FOR r IN SELECT viewname FROM pg_views WHERE schemaname = 'public' LOOP
                    EXECUTE format('DROP VIEW IF EXISTS public.%I CASCADE', r.viewname);
                END LOOP;
                FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations' LOOP
                    EXECUTE format('DROP TABLE IF EXISTS public.%I CASCADE', r.tablename);
                END LOOP;
                FOR r IN SELECT sequence_name FROM information_schema.sequences
                          WHERE sequence_schema = 'public' AND sequence_name <> 'migrations_id_seq' LOOP
                    EXECUTE format('DROP SEQUENCE IF EXISTS public.%I CASCADE', r.sequence_name);
                END LOOP;
                FOR r IN SELECT p.oid::regprocedure AS firma FROM pg_proc p
                          JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' LOOP
                    EXECUTE 'DROP FUNCTION IF EXISTS ' || r.firma || ' CASCADE';
                END LOOP;
                FOR r IN SELECT t.typname FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
                          WHERE n.nspname = 'public' AND t.typtype = 'e' LOOP
                    EXECUTE format('DROP TYPE IF EXISTS public.%I CASCADE', r.typname);
                END LOOP;
            END $$;
            SQL);
    }
};
