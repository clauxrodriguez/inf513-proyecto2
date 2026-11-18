<?php

namespace App\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CU6 - Certificado oficial (CL-) / constancia de descuento (CD-) con QR, anulación, verificación pública
 * y entrega de la constancia en ventanilla (Sección III).
 */
class CertificadoRepository
{
    /**
     * Trámites listos para emitir: NACIONAL firmado, EXTERIOR visado por Rectorado,
     * o CONCLUIDO cuyo certificado fue anulado (reemisión). Misma condición que trg_certificado_emision.
     */
    public function listosParaEmision(): Collection
    {
        return DB::table('tramite as t')
            ->join('usuario as est', 'est.id', '=', 't.solicitante_id')
            ->join('carrera as c', 'c.id', '=', 't.carrera_id')
            ->leftJoin('solicitud_descuento as sd', 'sd.tramite_id', '=', 't.id')
            ->where(function ($query) {
                $query->where(fn ($q) => $q->where('t.estado', 'FIRMADO_DIGITALMENTE')->where('t.destino', 'NACIONAL'))
                    ->orWhere(fn ($q) => $q->where('t.estado', 'DERIVADO_RECTORADO')
                        ->whereExists(fn ($v) => $v->from('visado_rectorado as v')->whereColumn('v.tramite_id', 't.id')))
                    ->orWhere(fn ($q) => $q->where('t.estado', 'CONCLUIDO')
                        ->whereNotExists(fn ($c) => $c->from('certificado_oficial as co')->whereColumn('co.tramite_id', 't.id')->where('co.estado', 'VIGENTE')));
            })
            ->select(
                't.id', 't.codigo', 't.tipo', 't.estado', 't.destino', 't.pais_destino', 't.institucion_destino', 't.prioridad',
                't.total_fojas', 't.fecha_ingreso',
                DB::raw("est.nombres || ' ' || est.apellidos as solicitante"),
                'est.registro', 'est.ci', 'c.nombre as carrera', 'sd.financiamiento'
            )
            ->orderByDesc('t.prioridad')
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

    public function reservarNumero(int $tramiteId): string
    {
        return DB::selectOne('SELECT fn_reservar_numero_certificado(?) AS numero', [$tramiteId])->numero;
    }

    /**
     * Leyenda legal de la firma (autoridad, calidad titular/interino con resolución, firma remota).
     */
    public function leyendaFirma(int $firmaId): ?string
    {
        return DB::selectOne('SELECT fn_leyenda_firma(?) AS leyenda', [$firmaId])->leyenda;
    }

    /**
     * fn_trg_certificado completa tipo y leyenda y valida el QR; trg_certificado_emision valida que el trámite esté listo.
     *
     * @param  array{tramite_id: int, firma_id: int, numero: string, ruta_pdfa: string, hash_sha256: string, codigo_verificacion: string, qr_contenido: string}  $datos
     */
    public function crear(array $datos): int
    {
        return DB::table('certificado_oficial')->insertGetId($datos);
    }

    /**
     * fn_trg_certificado_anular registra fecha y autor, impide cambios posteriores y notifica al solicitante.
     */
    public function anular(int $id, string $motivo): void
    {
        DB::table('certificado_oficial')->where('id', $id)->update(['estado' => 'ANULADO', 'motivo_anulacion' => $motivo]);
    }

    /**
     * Dictamen oficial: VALIDO, ANULADO, HASH_ALTERADO o NO_ENCONTRADO.
     */
    public function verificar(string $codigo, ?string $hash): object
    {
        return DB::selectOne('SELECT * FROM fn_verificar_certificado(?, ?)', [$codigo, $hash]);
    }

    /**
     * Sección III: entrega única de la constancia (trg_descuento valida beneficio, conclusión y unicidad).
     */
    public function registrarEntregaConstancia(int $tramiteId, string $nroFormulario, string $fechaEntrega): void
    {
        DB::table('solicitud_descuento')->where('tramite_id', $tramiteId)
            ->update(['nro_formulario_emitido' => $nroFormulario, 'fecha_entrega' => $fechaEntrega]);
    }

    private function consultaCertificados()
    {
        return DB::table('certificado_oficial as co')
            ->join('tramite as t', 't.id', '=', 'co.tramite_id')
            ->join('usuario as est', 'est.id', '=', 't.solicitante_id')
            ->leftJoin('usuario as an', 'an.id', '=', 'co.anulado_por')
            ->leftJoin('solicitud_descuento as sd', 'sd.tramite_id', '=', 't.id')
            ->select(
                'co.id', 'co.tramite_id', 'co.numero', 'co.tipo', 'co.ruta_pdfa', 'co.hash_sha256', 'co.codigo_verificacion',
                'co.leyenda_firma', 'co.qr_contenido', 'co.fecha_emision', 'co.estado', 'co.fecha_anulacion', 'co.motivo_anulacion',
                't.codigo as tramite_codigo', 't.tipo as tramite_tipo', 't.destino', 't.pais_destino',
                DB::raw("est.nombres || ' ' || est.apellidos as titular"), 'est.registro', 'est.ci',
                DB::raw("an.nombres || ' ' || an.apellidos as anulado_por_nombre"),
                'sd.financiamiento', 'sd.nro_formulario_emitido', 'sd.fecha_entrega'
            );
    }
}
