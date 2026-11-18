<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora de accesos, contador de visitas, menú dinámico por rol y usuario de auditoría de la sesión.
 */
class AccesoRepository
{
    /**
     * @param  array{usuario_id: ?int, email_intento: string, accion: string, ruta: string, metodo: string, ip_origen: ?string, user_agent: ?string}  $registro
     */
    public function registrarAcceso(array $registro): void
    {
        DB::table('bitacora_acceso')->insert($registro);
    }

    public function menusPorRol(string $rol): Collection
    {
        return DB::table('menu_opcion')
            ->where('rol', $rol)
            ->where('activo', true)
            ->orderBy('orden')
            ->get();
    }

    public function registrarVisita(string $ruta, string $tituloPagina): ?object
    {
        DB::statement('
            INSERT INTO contador_visita (ruta, titulo_pagina, visitas, ultima_visita)
            VALUES (?, ?, 1, LOCALTIMESTAMP)
            ON CONFLICT (ruta) DO UPDATE SET
                visitas = contador_visita.visitas + 1,
                titulo_pagina = EXCLUDED.titulo_pagina,
                ultima_visita = LOCALTIMESTAMP
        ', [$ruta, $tituloPagina]);

        return DB::table('contador_visita')->where('ruta', $ruta)->first();
    }

    /**
     * Los triggers de auditoría leen app.usuario_id para registrar quién hizo cada cambio.
     */
    public function establecerUsuarioAuditoria(int $usuarioId): void
    {
        DB::statement("SELECT set_config('app.usuario_id', ?, false)", [(string) $usuarioId]);
    }
}
