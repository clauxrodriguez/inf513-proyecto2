<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\ArchivoRepository;
use App\Repositories\CertificadoRepository;
use App\Repositories\SeguimientoRepository;
use App\Repositories\TramiteRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Portal público: seguimiento estudiantil (Registro + C.I.) y verificación de autenticidad del certificado con QR.
 */
class SeguimientoService
{
    /** @var array<string, string> */
    public const ESTADOS_LEGIBLES = [
        'SOLICITADO_ORIGEN' => 'Solicitado: en preparación por las unidades emisoras',
        'EN_FIRMA_ORIGEN' => 'En firma digital de las autoridades de origen',
        'INGRESADO' => 'Ingresado a la Ventanilla del Vicerrectorado',
        'EN_REVISION' => 'En revisión de requisitos y recibos',
        'OBSERVADO' => 'Observado: requiere subsanación',
        'EN_BANDEJA_DESPACHO' => 'En despacho para la firma del Vicerrectorado',
        'FIRMADO_DIGITALMENTE' => 'Firmado digitalmente por el Vicerrectorado',
        'DERIVADO_RECTORADO' => 'En Rectorado para la firma institucional',
        'CONCLUIDO' => 'Concluido: certificado emitido',
        'RECHAZADO' => 'Rechazado',
    ];

    public function __construct(
        private SeguimientoRepository $seguimiento,
        private TramiteRepository $tramites,
        private CertificadoRepository $certificados,
        private ArchivoRepository $archivos,
        private VerificacionFirmasService $verificacion,
        private ValidadorAgeticService $agetic,
    ) {}

    /**
     * Trámites del estudiante con estado legible, observaciones pendientes, avance SLA, flujo y certificado.
     */
    public function consultar(string $registro, string $ci): Collection
    {
        $tramites = $this->seguimiento->consultar(trim($registro), trim($ci));
        if ($tramites->isEmpty()) {
            throw ReglaNegocioException::noEncontrado('No se encontraron trámites para el Registro Universitario y C.I. ingresados.');
        }
        $sla = $this->seguimiento->slaDe($tramites->pluck('id'));

        return $tramites->map(function (object $tramite) use ($sla): object {
            $tramite->estado_nombre = self::ESTADOS_LEGIBLES[$tramite->estado] ?? $tramite->estado;
            $tramite->sla = $sla->get($tramite->id);
            $tramite->flujo = $this->tramites->flujoDe($tramite);
            $tramite->descarga_disponible = $tramite->codigo_verificacion !== null;
            unset($tramite->tipo_tramite_id);

            return $tramite;
        });
    }

    /**
     * Dictamen: VALIDO, ANULADO, HASH_ALTERADO (la huella del QR no coincide) o NO_ENCONTRADO.
     *
     * @return array<string, mixed>
     */
    public function verificar(string $codigo, ?string $hash): array
    {
        $codigo = trim($codigo);
        $hash = $hash !== null && trim($hash) !== '' ? strtolower(trim($hash)) : null;
        $certificado = Str::isUuid($codigo) ? $this->certificados->buscarPorCodigo($codigo) : null;

        if (! $certificado) {
            return ['codigo' => $codigo, 'resultado' => 'NO_ENCONTRADO', 'hash_verificado' => $hash !== null];
        }

        $resultado = match (true) {
            $certificado->estado === 'ANULADO' => 'ANULADO',
            $hash !== null && $hash !== $certificado->hash_sha256 => 'HASH_ALTERADO',
            default => 'VALIDO',
        };
        $anulacion = $resultado === 'ANULADO' ? $this->certificados->anulacionDe($certificado->id) : null;

        return [
            'codigo' => $codigo,
            'resultado' => $resultado,
            'hash_verificado' => $hash !== null,
            'numero' => $certificado->numero,
            'tipo' => $certificado->tipo,
            'titular' => $certificado->titular,
            'carrera' => $certificado->carrera,
            'tramite_codigo' => $certificado->tramite_codigo,
            'destino' => $certificado->destino,
            'pais_destino' => $certificado->pais_destino,
            'fecha_emision' => $certificado->fecha_emision,
            'hash_sha256' => $certificado->hash_sha256,
            'fecha_anulacion' => $anulacion?->fecha,
            'motivo_anulacion' => $anulacion?->motivo,
        ];
    }

    /**
     * Validación pública de un PDF: firmas e integridad (Validador Web de AGETIC si está configurado; si no,
     * el verificador local) y su correspondencia con un certificado oficial, sea el propio certificado
     * (por su código) o el expediente firmado al que apunta el QR (por su huella SHA-256).
     *
     * @return array{hash_sha256: string, firmas: array<string, mixed>, certificado: ?array<string, mixed>}
     */
    public function validarPdf(UploadedFile $archivo, ?string $codigo): array
    {
        $pdf = $this->archivos->leer($archivo->getRealPath());
        $hash = hash('sha256', $pdf);
        $firmas = $this->agetic->configurado() ? $this->agetic->verificar($pdf) : $this->verificacion->verificar($pdf);

        $certificado = $codigo !== null && Str::isUuid(trim($codigo))
            ? $this->certificados->buscarPorCodigo(trim($codigo))
            : $this->certificados->buscarPorHashExpediente($hash);
        $coincide = null;
        if ($certificado) {
            $rutaCertificado = $this->archivos->rutaEnDisco($certificado->ruta_pdfa);
            $coincide = match (true) {
                $rutaCertificado !== null && hash_file('sha256', $rutaCertificado) === $hash => 'CERTIFICADO',
                $certificado->hash_sha256 === $hash => 'EXPEDIENTE',
                default => 'NO_COINCIDE',
            };
        }

        return [
            'hash_sha256' => $hash,
            'firmas' => $firmas,
            'certificado' => $certificado ? [
                ...$this->verificar($certificado->codigo_verificacion, $coincide === 'NO_COINCIDE' ? $hash : null),
                'coincide' => $coincide,
            ] : null,
        ];
    }

    /**
     * Descarga pública del PDF/A por su código de verificación.
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
