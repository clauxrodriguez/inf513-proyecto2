<?php

namespace App\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU6 - Certificado oficial (CL-) / constancia de diplomado (CD-) con QR, anulación y verificación pública.
 * El trigger trg_certificado genera el número, el código de verificación (UUID) y el contenido del QR.
 */
class CertificadoRepository
{
    /**
     * Trámites listos para el certificado: firmados por el Vicerrectorado (nacional o descuento),
     * al exterior con la firma de Rectorado, o concluidos cuyo certificado fue anulado (reemisión).
     */
    public function listosParaEmision(): Collection
    {
        return DB::table('tramite as t')
            ->join('tipo_tramite as tt', 'tt.id', '=', 't.tipo_tramite_id')
            ->join('usuario as est', 'est.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->leftJoin('solicitud_descuento as sd', 'sd.tramite_id', '=', 't.id')
            ->where(function ($consulta) {
                $consulta->where(fn ($q) => $q->where('t.estado', 'FIRMADO_DIGITALMENTE')->whereRaw("t.destino IS DISTINCT FROM 'EXTERIOR'"))
                    ->orWhere(fn ($q) => $q->where('t.estado', 'DERIVADO_RECTORADO')
                        ->whereExists(fn ($f) => $f->selectRaw('1')->from('firma_digital as f')->whereColumn('f.tramite_id', 't.id')
                            ->where('f.etapa', 'RECTORADO')->where('f.valida', true)))
                    ->orWhere(fn ($q) => $q->where('t.estado', 'CONCLUIDO')
                        ->whereNotExists(fn ($co) => $co->selectRaw('1')->from('certificado_oficial as co')->whereColumn('co.tramite_id', 't.id')->where('co.estado', 'VIGENTE')));
            })
            ->select(
                't.id', 't.codigo', 'tt.codigo as tipo', 't.estado', 't.destino', 't.pais_destino', 't.institucion_destino', 't.prioridad',
                't.total_fojas', 't.fecha_ingreso',
                DB::raw("est.nombres || ' ' || est.apellidos as solicitante"),
                'est.registro', 'est.ci', 'c.nombre as carrera', 'sd.financiamiento'
            )
            ->orderByRaw("CASE t.prioridad WHEN 'URGENTE' THEN 1 WHEN 'ALTA' THEN 2 ELSE 3 END")
            ->orderBy('t.fecha_ingreso')
            ->get();
    }

    public function emitidos(int $limite = 200): Collection
    {
        return $this->consultaCertificados()->orderByDesc('co.id')->limit($limite)->get();
    }

    public function buscar(int $id): ?object
    {
        return $this->consultaCertificados()->where('co.id', $id)->first();
    }

    public function buscarPorCodigo(string $codigoVerificacion): ?object
    {
        return $this->consultaCertificados()->where('co.codigo_verificacion', $codigoVerificacion)->first();
    }

    /**
     * Certificado cuya huella del expediente firmado (la del QR) coincide con la indicada; prefiere el vigente.
     */
    public function buscarPorHashExpediente(string $hash): ?object
    {
        return $this->consultaCertificados()->where('co.hash_sha256', $hash)->orderByRaw("co.estado = 'VIGENTE' DESC")->orderByDesc('co.id')->first();
    }

    /**
     * @param  array{tramite_id: int, firma_id: int, tipo: string, ruta_pdfa: string, hash_sha256: string}  $datos
     */
    public function crear(array $datos): object
    {
        $id = DB::table('certificado_oficial')->insertGetId($datos);

        return DB::table('certificado_oficial')->where('id', $id)->first();
    }

    public function asignarArchivo(int $id, string $rutaPdfa): void
    {
        DB::table('certificado_oficial')->where('id', $id)->update(['ruta_pdfa' => $rutaPdfa]);
    }

    public function anular(int $id): void
    {
        DB::table('certificado_oficial')->where('id', $id)->update(['estado' => 'ANULADO']);
    }

    /**
     * Motivo y fecha de la anulación, registrados en la bitácora inalterable.
     */
    public function anulacionDe(int $certificadoId): ?object
    {
        return DB::table('bitacora')
            ->where('certificado_id', $certificadoId)
            ->where('accion', 'ANULAR_CERTIFICADO')
            ->orderByDesc('id')
            ->select('fecha', DB::raw("detalle->>'motivo' as motivo"))
            ->first();
    }

    private function consultaCertificados(): Builder
    {
        return DB::table('certificado_oficial as co')
            ->join('tramite as t', 't.id', '=', 'co.tramite_id')
            ->join('tipo_tramite as tt', 'tt.id', '=', 't.tipo_tramite_id')
            ->join('usuario as est', 'est.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->leftJoin('solicitud_descuento as sd', 'sd.tramite_id', '=', 't.id')
            ->select(
                'co.id', 'co.tramite_id', 'co.firma_id', 'co.numero', 'co.tipo', 'co.ruta_pdfa', 'co.hash_sha256', 'co.codigo_verificacion',
                'co.qr_contenido', 'co.fecha_emision', 'co.estado',
                't.codigo as tramite_codigo', 'tt.codigo as tramite_tipo', 'tt.nombre as tramite_tipo_nombre', 't.destino', 't.pais_destino',
                DB::raw("est.nombres || ' ' || est.apellidos as titular"), 'est.registro', 'est.ci', 'c.nombre as carrera',
                'sd.financiamiento'
            );
    }
}
