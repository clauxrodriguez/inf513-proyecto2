<?php

namespace App\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Estadísticas del negocio (trámites, SLA, recaudación, firmas) y del tráfico web (bitacora_acceso, contador_visita).
 * Los periodos son inclusivos: [desde 00:00, hasta 23:59:59].
 */
class EstadisticasRepository
{
    public function tramitesPorEstado(?string $desde, ?string $hasta, ?int $solicitanteId = null): Collection
    {
        return $this->tramitesEnPeriodo($desde, $hasta)
            ->when($solicitanteId !== null, fn ($q) => $q->where('t.solicitante_id', $solicitanteId))
            ->selectRaw('t.estado, COUNT(*) AS total')
            ->groupBy('t.estado')
            ->orderByDesc('total')
            ->get();
    }

    public function tramitesPorCarrera(?string $desde, ?string $hasta): Collection
    {
        return $this->tramitesEnPeriodo($desde, $hasta)
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->join('facultad as f', 'f.id', '=', 'c.facultad_id')
            ->selectRaw("c.nombre AS carrera, f.codigo AS facultad, COUNT(*) AS total, COUNT(*) FILTER (WHERE t.estado = 'CONCLUIDO') AS concluidos")
            ->groupBy('c.nombre', 'f.codigo')
            ->orderByDesc('total')
            ->get();
    }

    public function tramitesPorTipoYDestino(?string $desde, ?string $hasta): Collection
    {
        return $this->tramitesEnPeriodo($desde, $hasta)
            ->join('tipo_tramite as tt', 'tt.id', '=', 't.tipo_tramite_id')
            ->selectRaw("tt.nombre AS tipo, COALESCE(t.destino, 'NACIONAL') AS destino, COUNT(*) AS total")
            ->groupBy('tt.nombre', DB::raw("COALESCE(t.destino, 'NACIONAL')"))
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Recaudación de recibos de Caja por mes y concepto.
     */
    public function recaudacionMensual(?string $desde, ?string $hasta): Collection
    {
        return DB::table('comprobante_caja')
            ->when($desde, fn ($q) => $q->where('fecha_pago', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('fecha_pago', '<=', $hasta))
            ->selectRaw("to_char(fecha_pago, 'YYYY-MM') AS mes, concepto, SUM(importe) AS total, COUNT(*) AS recibos")
            ->groupBy('mes', 'concepto')
            ->orderBy('mes')
            ->get();
    }

    /**
     * @return array{ingresados: int, concluidos: int, observados: int, recaudado: float, firmas_despacho: int, firmas_remotas: int, firmas_suplente: int, firmas_origen: int}
     */
    public function totales(?string $desde, ?string $hasta): array
    {
        $tramites = $this->tramitesEnPeriodo($desde, $hasta)->selectRaw("
            COUNT(*) AS ingresados,
            COUNT(*) FILTER (WHERE t.estado = 'CONCLUIDO') AS concluidos,
            COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM observacion o WHERE o.tramite_id = t.id)) AS observados
        ")->first();

        $firmas = DB::table('firma_digital as fd')
            ->join('lote_firma as l', 'l.id', '=', 'fd.lote_id')
            ->where('fd.valida', true)
            ->when($desde, fn ($q) => $q->where('fd.fecha_firma', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereRaw('fd.fecha_firma < ?::date + 1', [$hasta]))
            ->selectRaw("
                COUNT(*) FILTER (WHERE fd.etapa = 'DESPACHO') AS despacho,
                COUNT(*) FILTER (WHERE fd.etapa = 'DESPACHO' AND l.es_remota) AS remotas,
                COUNT(*) FILTER (WHERE fd.etapa = 'DESPACHO' AND l.delegacion_id IS NOT NULL) AS suplente,
                COUNT(*) FILTER (WHERE fd.etapa = 'ORIGEN') AS origen
            ")->first();

        $recaudado = DB::table('comprobante_caja')
            ->when($desde, fn ($q) => $q->where('fecha_pago', '>=', $desde))
            ->when($hasta, fn ($q) => $q->where('fecha_pago', '<=', $hasta))
            ->sum('importe');

        return [
            'ingresados' => (int) $tramites->ingresados,
            'concluidos' => (int) $tramites->concluidos,
            'observados' => (int) $tramites->observados,
            'recaudado' => (float) $recaudado,
            'firmas_despacho' => (int) $firmas->despacho,
            'firmas_remotas' => (int) $firmas->remotas,
            'firmas_suplente' => (int) $firmas->suplente,
            'firmas_origen' => (int) $firmas->origen,
        ];
    }

    public function indicadoresDeGestion(): Collection
    {
        return DB::table('indicador_gestion')->orderBy('id')->get();
    }

    // ------------------------------------------------------------------ tráfico web

    /**
     * Inicios de sesión correctos y fallidos por día.
     */
    public function accesosPorDia(string $desde, string $hasta): Collection
    {
        return collect(DB::select("
            SELECT d::date AS dia,
                   COUNT(a.id) FILTER (WHERE a.accion = 'LOGIN_OK')       AS login_ok,
                   COUNT(a.id) FILTER (WHERE a.accion = 'LOGIN_FALLIDO')  AS login_fallido,
                   COUNT(a.id) FILTER (WHERE a.accion = 'ACCESO_RECURSO') AS accesos
              FROM generate_series(?::date, ?::date, INTERVAL '1 day') AS d
              LEFT JOIN bitacora_acceso a ON a.fecha >= d AND a.fecha < d + INTERVAL '1 day'
             GROUP BY d
             ORDER BY d
        ", [$desde, $hasta]));
    }

    public function recursosMasAccedidos(string $desde, string $hasta, int $limite = 10): Collection
    {
        return $this->accesosEnPeriodo($desde, $hasta)
            ->where('accion', 'ACCESO_RECURSO')
            ->selectRaw('ruta, metodo_http, COUNT(*) AS total, COUNT(DISTINCT usuario_id) AS usuarios')
            ->groupBy('ruta', 'metodo_http')
            ->orderByDesc('total')
            ->limit($limite)
            ->get();
    }

    public function ipsConMasFallidos(string $desde, string $hasta, int $limite = 10): Collection
    {
        return $this->accesosEnPeriodo($desde, $hasta)
            ->where('accion', 'LOGIN_FALLIDO')
            ->selectRaw('ip_origen, COUNT(*) AS intentos, COUNT(DISTINCT email_intento) AS correos, MAX(fecha) AS ultimo')
            ->groupBy('ip_origen')
            ->orderByDesc('intentos')
            ->limit($limite)
            ->get();
    }

    public function paginasMasVisitadas(int $limite = 10): Collection
    {
        return DB::table('contador_visita')->orderByDesc('visitas')->limit($limite)->get(['ruta', 'titulo_pagina', 'visitas', 'ultima_visita']);
    }

    /**
     * @return array{logins_ok: int, logins_fallidos: int, accesos: int, usuarios_distintos: int, ips_distintas: int}
     */
    public function totalesAcceso(string $desde, string $hasta): array
    {
        $fila = $this->accesosEnPeriodo($desde, $hasta)->selectRaw("
            COUNT(*) FILTER (WHERE accion = 'LOGIN_OK') AS logins_ok,
            COUNT(*) FILTER (WHERE accion = 'LOGIN_FALLIDO') AS logins_fallidos,
            COUNT(*) FILTER (WHERE accion = 'ACCESO_RECURSO') AS accesos,
            COUNT(DISTINCT usuario_id) AS usuarios_distintos,
            COUNT(DISTINCT ip_origen) AS ips_distintas
        ")->first();

        return array_map('intval', (array) $fila);
    }

    private function tramitesEnPeriodo(?string $desde, ?string $hasta): Builder
    {
        return DB::table('tramite as t')
            ->when($desde, fn ($q) => $q->where('t.fecha_ingreso', '>=', $desde))
            ->when($hasta, fn ($q) => $q->whereRaw('t.fecha_ingreso < ?::date + 1', [$hasta]));
    }

    private function accesosEnPeriodo(string $desde, string $hasta): Builder
    {
        return DB::table('bitacora_acceso')
            ->where('fecha', '>=', $desde)
            ->whereRaw('fecha < ?::date + 1', [$hasta]);
    }
}
