<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\ArchivoRepository;
use App\Repositories\CertificadoRepository;
use App\Repositories\SeguimientoRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * CU6 - Portal de seguimiento estudiantil (RF-16) y verificación pública de autenticidad (RF-15).
 */
class SeguimientoService
{
    public function __construct(
        private SeguimientoRepository $seguimiento,
        private CertificadoRepository $certificados,
        private ArchivoRepository $archivos,
        private FirmadorService $firmador,
    ) {}

    /**
     * Trámites del estudiante con estado, observaciones pendientes, avance SLA, fase del flujo y certificado.
     */
    public function consultar(string $registro, string $ci): Collection
    {
        $tramites = $this->seguimiento->consultar(trim($registro), trim($ci));
        if ($tramites->isEmpty()) {
            throw ReglaNegocioException::noEncontrado('No se encontraron trámites para el Registro Universitario y C.I. ingresados.');
        }
        $codigos = $tramites->pluck('codigo');
        $sla = $this->seguimiento->slaDe($codigos);
        $flujos = $this->seguimiento->flujoDe($codigos);

        return $tramites->map(function ($tramite) use ($sla, $flujos) {
            $tramite->sla = $sla->get($tramite->codigo);
            $tramite->flujo = $flujos->get($tramite->codigo, collect())->values();
            $tramite->descarga_disponible = $tramite->codigo_verificacion !== null;

            return $tramite;
        });
    }

    /**
     * Dictamen oficial de fn_verificar_certificado, con el detalle de la anulación si corresponde.
     *
     * @return array<string, mixed>
     */
    public function verificar(string $codigo, ?string $hash): array
    {
        $codigo = trim($codigo);
        if (! Str::isUuid($codigo)) {
            return ['resultado' => 'NO_ENCONTRADO', 'codigo' => $codigo];
        }
        $hash = $hash !== null && trim($hash) !== '' ? strtolower(trim($hash)) : null;
        $dictamen = (array) $this->certificados->verificar($codigo, $hash);

        if ($dictamen['resultado'] === 'ANULADO') {
            $certificado = $this->certificados->buscarPorCodigo($codigo);
            $dictamen['fecha_anulacion'] = $certificado?->fecha_anulacion;
            $dictamen['motivo_anulacion'] = $certificado?->motivo_anulacion;
        }

        return ['codigo' => $codigo, 'hash_verificado' => $hash !== null, ...$dictamen];
    }

    /**
     * Verificación criptográfica de un PDF presentado por un tercero (firma incrustada íntegra y de la AC).
     *
     * @return array<string, mixed>
     */
    public function verificarArchivo(UploadedFile $pdf): array
    {
        return $this->firmador->verificar($this->archivos->leer($pdf->getRealPath()));
    }

    /**
     * Descarga pública del PDF/A por su código de verificación (lo conoce el titular y quien recibe el documento).
     *
     * @return array{ruta: string, numero: string}
     */
    public function documentoPublico(string $codigo): array
    {
        $certificado = Str::isUuid($codigo) ? $this->certificados->buscarPorCodigo($codigo) : null;
        if (! $certificado) {
            throw ReglaNegocioException::noEncontrado('El certificado no existe.');
        }
        if ($certificado->estado !== 'VIGENTE') {
            throw new ReglaNegocioException("El certificado {$certificado->numero} fue ANULADO y ya no puede descargarse.", 410);
        }
        $ruta = $this->archivos->rutaEnDisco($certificado->ruta_pdfa)
            ?? throw ReglaNegocioException::noEncontrado('No se encontró el archivo del certificado.');

        return ['ruta' => $ruta, 'numero' => $certificado->numero];
    }
}
