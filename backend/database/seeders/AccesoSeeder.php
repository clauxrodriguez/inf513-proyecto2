<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Roles, permisos, matriz de acceso (rol - permiso), menú dinámico y contador de visitas.
 */
class AccesoSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('rol')->insert([
            ['codigo' => 'ADMIN', 'nombre' => 'Administrador del Sistema', 'descripcion' => 'Configuración global, usuarios, roles, permisos y auditoría'],
            ['codigo' => 'DIRECTOR_CARRERA', 'nombre' => 'Director / Jefe de Carrera', 'descripcion' => 'Emisión y primera firma digital en origen'],
            ['codigo' => 'DECANO', 'nombre' => 'Decano / Vicedecano', 'descripcion' => 'Validación y firma digital facultativa'],
            ['codigo' => 'ADMISIONES', 'nombre' => 'Jefe de Admisiones y Registro', 'descripcion' => 'Emisión y firma digital de certificados de notas e históricos'],
            ['codigo' => 'DAGA', 'nombre' => 'Jefe de DAGA / DICA', 'descripcion' => 'Visado y firma digital de acreditación académica'],
            ['codigo' => 'RECEPCION', 'nombre' => 'Recepción / Ventanilla', 'descripcion' => 'Recepción, validación de requisitos, recibos de caja y compilación'],
            ['codigo' => 'VICERRECTOR', 'nombre' => 'Vicerrector/a', 'descripcion' => 'Autoridad académica de despacho y firma digital'],
            ['codigo' => 'RECTOR', 'nombre' => 'Rector/a', 'descripcion' => 'Firma digital institucional final para trámites al exterior'],
            ['codigo' => 'ESTUDIANTE', 'nombre' => 'Estudiante / Egresado', 'descripcion' => 'Solicitante de trámites y seguimiento web'],
        ]);

        DB::table('permiso')->insert([
            ['codigo' => 'DASHBOARD_VER', 'nombre' => 'Ver Dashboard', 'descripcion' => 'Acceso al panel principal e indicadores'],
            ['codigo' => 'USUARIOS_GESTIONAR', 'nombre' => 'Gestionar Usuarios y Roles', 'descripcion' => 'Administración de cuentas, roles y permisos'],
            ['codigo' => 'DELEGACION_GESTIONAR', 'nombre' => 'Gestionar Delegaciones', 'descripcion' => 'Registro y revocación de suplencias interinas'],
            ['codigo' => 'TRAMITES_SOLICITAR', 'nombre' => 'Solicitar Trámites', 'descripcion' => 'Creación de solicitudes de legalización y descuento'],
            ['codigo' => 'TRAMITES_ORIGEN', 'nombre' => 'Bandeja de Emisión Origen', 'descripcion' => 'Emisión y firma digital en Carrera, Decanato, Admisiones y DAGA'],
            ['codigo' => 'TRAMITES_RECEPCION', 'nombre' => 'Ventanilla y Compilación', 'descripcion' => 'Cotejo, validación de recibos de caja, requisitos y compilación PDF/A'],
            ['codigo' => 'DESPACHO_FIRMAR', 'nombre' => 'Bandeja de Despacho y Firma', 'descripcion' => 'Previsualización y firma digital por lotes o remota'],
            ['codigo' => 'RECTORADO_VISAR', 'nombre' => 'Visado Final Rectorado', 'descripcion' => 'Firma institucional para trámites con destino al exterior'],
            ['codigo' => 'SLA_MONITOREAR', 'nombre' => 'Monitoreo de Alertas SLA', 'descripcion' => 'Control de tiempos de 72h hábiles y atención de alertas'],
            ['codigo' => 'REPORTES_VER', 'nombre' => 'Reportes y Estadísticas', 'descripcion' => 'Generación de reportes gerenciales en PDF y Excel'],
            ['codigo' => 'BITACORA_AUDITAR', 'nombre' => 'Auditoría y Bitácora', 'descripcion' => 'Consulta de bitácora SHA-256 y accesos web'],
            ['codigo' => 'SLA_CONFIGURAR', 'nombre' => 'Configurar SLA', 'descripcion' => 'Parametrización de plazos y porcentaje de alerta del SLA'],
            ['codigo' => 'PAGOS_REALIZAR', 'nombre' => 'Pagos en línea', 'descripcion' => 'Billetera de tarjetas y pago al contado del arancel con Stripe'],
        ]);

        $roles = DB::table('rol')->pluck('id', 'codigo');
        $permisos = DB::table('permiso')->pluck('id', 'codigo');

        $autoridadesOrigen = ['DASHBOARD_VER', 'TRAMITES_ORIGEN', 'SLA_MONITOREAR'];

        /** @var array<string, list<string>> $matriz */
        $matriz = [
            'ADMIN' => $permisos->keys()->all(),
            'DIRECTOR_CARRERA' => $autoridadesOrigen,
            // El Decano puede suplir al Vicerrector (delegación ACTIVA): ve la bandeja de despacho
            'DECANO' => [...$autoridadesOrigen, 'DESPACHO_FIRMAR'],
            'ADMISIONES' => $autoridadesOrigen,
            'DAGA' => $autoridadesOrigen,
            'RECEPCION' => ['DASHBOARD_VER', 'TRAMITES_RECEPCION', 'SLA_MONITOREAR', 'REPORTES_VER'],
            // TRAMITES_ORIGEN: firma en origen de la Resolución Vicerrectoral (RES_VICERRECT)
            'VICERRECTOR' => ['DASHBOARD_VER', 'TRAMITES_ORIGEN', 'DESPACHO_FIRMAR', 'SLA_MONITOREAR', 'REPORTES_VER'],
            // La suplencia del Vicerrector la designa el Rectorado por Resolución Rectoral
            'RECTOR' => ['DASHBOARD_VER', 'DELEGACION_GESTIONAR', 'RECTORADO_VISAR', 'REPORTES_VER'],
            'ESTUDIANTE' => ['DASHBOARD_VER', 'TRAMITES_SOLICITAR', 'PAGOS_REALIZAR'],
        ];

        foreach ($matriz as $rol => $codigosPermiso) {
            DB::table('rol_permiso')->insert(array_map(fn (string $codigo): array => [
                'rol_id' => $roles[$rol],
                'permiso_id' => $permisos[$codigo],
            ], $codigosPermiso));
        }

        /**
         * Una opción sin ruta agrupa a sus hijas; se muestra solo si el rol ve al menos una de ellas.
         *
         * @var list<array{permiso: ?string, titulo: string, ruta: ?string, icono: string, hijos?: list<array{permiso: string, titulo: string, ruta: string, icono: string}>}> $menu
         */
        $menu = [
            ['permiso' => 'DASHBOARD_VER', 'titulo' => 'Inicio / Dashboard', 'ruta' => '/dashboard', 'icono' => 'LayoutDashboard'],
            ['permiso' => 'TRAMITES_SOLICITAR', 'titulo' => 'Mis Trámites', 'ruta' => null, 'icono' => 'FileText', 'hijos' => [
                ['permiso' => 'TRAMITES_SOLICITAR', 'titulo' => 'Consultar mis trámites', 'ruta' => '/mis-tramites', 'icono' => 'List'],
                ['permiso' => 'TRAMITES_SOLICITAR', 'titulo' => 'Solicitar trámite', 'ruta' => '/mis-tramites/nuevo', 'icono' => 'FilePlus'],
            ]],
            ['permiso' => 'PAGOS_REALIZAR', 'titulo' => 'Pagos', 'ruta' => null, 'icono' => 'CreditCard', 'hijos' => [
                ['permiso' => 'PAGOS_REALIZAR', 'titulo' => 'Mis métodos de pago', 'ruta' => '/mis-metodos-pago', 'icono' => 'Wallet'],
                ['permiso' => 'PAGOS_REALIZAR', 'titulo' => 'Historial de pagos', 'ruta' => '/mis-pagos', 'icono' => 'Receipt'],
            ]],
            ['permiso' => 'TRAMITES_ORIGEN', 'titulo' => 'Bandeja Facultativa', 'ruta' => '/bandeja-origen', 'icono' => 'Building2'],
            ['permiso' => 'TRAMITES_RECEPCION', 'titulo' => 'Ventanilla Legalización', 'ruta' => null, 'icono' => 'Inbox', 'hijos' => [
                ['permiso' => 'TRAMITES_RECEPCION', 'titulo' => 'Revisión de trámites', 'ruta' => '/recepcion', 'icono' => 'FolderCheck'],
                ['permiso' => 'TRAMITES_RECEPCION', 'titulo' => 'Certificados QR', 'ruta' => '/certificados', 'icono' => 'QrCode'],
                ['permiso' => 'TRAMITES_RECEPCION', 'titulo' => 'Pagos en línea', 'ruta' => '/recepcion/pagos', 'icono' => 'CreditCard'],
            ]],
            ['permiso' => 'DESPACHO_FIRMAR', 'titulo' => 'Despacho y Firma Digital', 'ruta' => '/despacho-firma', 'icono' => 'FileSignature'],
            ['permiso' => 'RECTORADO_VISAR', 'titulo' => 'Visado Rectorado', 'ruta' => '/rectorado', 'icono' => 'Award'],
            ['permiso' => 'DELEGACION_GESTIONAR', 'titulo' => 'Delegación de Autoridad', 'ruta' => '/delegaciones', 'icono' => 'UserCheck'],
            ['permiso' => 'SLA_MONITOREAR', 'titulo' => 'Monitor SLA (72h)', 'ruta' => '/monitor-sla', 'icono' => 'Clock'],
            ['permiso' => 'REPORTES_VER', 'titulo' => 'Reportes y Estadísticas', 'ruta' => '/reportes', 'icono' => 'BarChart3'],
            ['permiso' => 'DASHBOARD_VER', 'titulo' => 'Guía Procesal', 'ruta' => '/guia', 'icono' => 'BookOpen'],
            ['permiso' => null, 'titulo' => 'Administración', 'ruta' => null, 'icono' => 'Settings', 'hijos' => [
                ['permiso' => 'USUARIOS_GESTIONAR', 'titulo' => 'Usuarios y Roles', 'ruta' => '/admin/usuarios', 'icono' => 'Users'],
                ['permiso' => 'BITACORA_AUDITAR', 'titulo' => 'Bitácora y Auditoría', 'ruta' => '/admin/bitacora', 'icono' => 'ShieldCheck'],
            ]],
        ];

        foreach ($menu as $orden => $opcion) {
            $padreId = $this->crearOpcion($opcion, null, $orden + 1, $permisos->all());

            foreach ($opcion['hijos'] ?? [] as $ordenHijo => $hijo) {
                $this->crearOpcion($hijo, $padreId, $ordenHijo + 1, $permisos->all());
            }
        }
    }

    /**
     * Crea la opción de menú y, si tiene ruta, su contador de visitas.
     *
     * @param  array{permiso: ?string, titulo: string, ruta: ?string, icono: string}  $opcion
     * @param  array<string, int>  $permisos
     */
    private function crearOpcion(array $opcion, ?int $padreId, int $orden, array $permisos): int
    {
        $id = DB::table('menu_opcion')->insertGetId([
            'padre_id' => $padreId,
            'permiso_id' => $opcion['permiso'] === null ? null : $permisos[$opcion['permiso']],
            'titulo' => $opcion['titulo'],
            'ruta' => $opcion['ruta'],
            'icono' => $opcion['icono'],
            'orden' => $orden,
        ]);

        if ($opcion['ruta'] !== null) {
            DB::table('contador_visita')->insert([
                'ruta' => $opcion['ruta'],
                'titulo_pagina' => $opcion['titulo'],
                'menu_opcion_id' => $id,
            ]);
        }

        return $id;
    }
}
