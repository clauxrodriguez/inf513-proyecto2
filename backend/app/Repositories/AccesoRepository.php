<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RBAC (usuario_rol -> rol_permiso -> permiso -> menu_opcion), bitácora de accesos web,
 * contador de visitas y contexto de auditoría de cada transacción.
 */
class AccesoRepository
{
    /**
     * @param  array{usuario_id: ?int, email_intento: ?string, accion: string, ruta: string, metodo_http: string, ip_origen: ?string, user_agent: ?string}  $registro
     */
    public function registrarAcceso(array $registro): void
    {
        DB::table('bitacora_acceso')->insert($registro);
    }

    /**
     * Roles activos del usuario.
     *
     * @return Collection<int, object{codigo: string, nombre: string}>
     */
    public function rolesDeUsuario(int $usuarioId): Collection
    {
        return DB::table('usuario_rol as ur')
            ->join('rol as r', 'r.id', '=', 'ur.rol_id')
            ->where('ur.usuario_id', $usuarioId)
            ->where('r.activo', true)
            ->orderBy('r.id')
            ->get(['r.codigo', 'r.nombre']);
    }

    /**
     * Códigos de permiso concedidos (y activos) a un rol.
     *
     * @return list<string>
     */
    public function permisosDeRol(string $rol): array
    {
        return DB::table('rol_permiso as rp')
            ->join('rol as r', 'r.id', '=', 'rp.rol_id')
            ->join('permiso as p', 'p.id', '=', 'rp.permiso_id')
            ->where('r.codigo', $rol)
            ->where('r.activo', true)
            ->where('rp.activo', true)
            ->orderBy('p.codigo')
            ->pluck('p.codigo')
            ->all();
    }

    /**
     * Opciones de menú activas que el rol puede ver (las que no exigen permiso son agrupadoras).
     *
     * @return Collection<int, object{id: int, padre_id: ?int, titulo: string, ruta: ?string, icono: ?string, orden: int}>
     */
    public function opcionesMenuDeRol(string $rol): Collection
    {
        return DB::table('menu_opcion as m')
            ->where('m.activo', true)
            ->where(function ($consulta) use ($rol) {
                $consulta->whereNull('m.permiso_id')
                    ->orWhereExists(function ($permitido) use ($rol) {
                        $permitido->selectRaw('1')
                            ->from('rol_permiso as rp')
                            ->join('rol as r', 'r.id', '=', 'rp.rol_id')
                            ->whereColumn('rp.permiso_id', 'm.permiso_id')
                            ->where('r.codigo', $rol)
                            ->where('r.activo', true)
                            ->where('rp.activo', true);
                    });
            })
            ->orderBy('m.orden')
            ->orderBy('m.id')
            ->get(['m.id', 'm.padre_id', 'm.titulo', 'm.ruta', 'm.icono', 'm.orden']);
    }

    /**
     * Suma una visita a la página y la vincula con su opción de menú cuando existe.
     *
     * @return object{ruta: string, titulo_pagina: string, visitas: int}
     */
    public function registrarVisita(string $ruta, string $tituloPagina): object
    {
        return DB::selectOne('
            INSERT INTO contador_visita (ruta, titulo_pagina, menu_opcion_id, visitas, ultima_visita)
            VALUES (?, ?, (SELECT id FROM menu_opcion WHERE ruta = ? ORDER BY id LIMIT 1), 1, LOCALTIMESTAMP)
            ON CONFLICT (ruta) DO UPDATE SET
                visitas        = contador_visita.visitas + 1,
                titulo_pagina  = EXCLUDED.titulo_pagina,
                menu_opcion_id = EXCLUDED.menu_opcion_id,
                ultima_visita  = LOCALTIMESTAMP
            RETURNING ruta, titulo_pagina, visitas
        ', [$ruta, $tituloPagina, $ruta]);
    }

    /**
     * Variables locales de la transacción en curso (set_config(..., true)) que leen los triggers:
     * quién opera (bitácora, registrado_por) y la URL del validador público (QR del certificado).
     */
    public function establecerContextoAuditoria(int $usuarioId, string $urlValidador): void
    {
        DB::statement(
            "SELECT set_config('app.usuario_id', ?, true), set_config('app.url_validador', ?, true)",
            [(string) $usuarioId, $urlValidador],
        );
    }
}
