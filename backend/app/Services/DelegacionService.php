<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\ArchivoRepository;
use App\Repositories\DelegacionRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use App\Repositories\UsuarioRepository;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;

/**
 * CU2 - Delegación de autoridad, certificados digitales de firma e indicadores de gestión.
 */
class DelegacionService
{
    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private DelegacionRepository $delegaciones,
        private UsuarioRepository $usuarios,
        private TramiteRepository $tramites,
        private ArchivoRepository $archivos,
        private FirmadorService $firmador,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function panel(): array
    {
        return [
            'delegaciones' => $this->delegaciones->listar(),
            'vicerrectores' => $this->usuarios->vicerrectoresActivos(),
            'suplentes' => $this->usuarios->decanosActivos(),
            'certificados' => $this->delegaciones->listarCertificados(),
            'indicadores' => $this->tramites->indicadores(),
        ];
    }

    /**
     * La suplencia exige la resolución firmada en PDF; trg_delegacion_validar aplica las reglas estatutarias.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrar(array $datos, UploadedFile $resolucion, int $registradoPor): int
    {
        $rutaPdf = $this->archivos->guardarSubido($resolucion, 'resoluciones');

        try {
            return $this->delegaciones->crear([
                ...$datos,
                'archivo_resolucion_url' => $rutaPdf,
                'estado' => 'ACTIVA',
                'registrado_por' => $registradoPor,
            ]);
        } catch (QueryException $e) {
            $this->archivos->eliminar($rutaPdf);
            throw $e;
        }
    }

    public function revocar(int $id, string $motivo, int $revocadoPor): void
    {
        $this->delegaciones->actualizar($id, [
            'estado' => 'REVOCADA',
            'revocado_por' => $revocadoPor,
            'fecha_revocacion' => now(),
            'motivo_revocacion' => $motivo,
        ]);
    }

    /**
     * Emite el certificado con la AC de prueba (en producción, ADSIB) y lo activa reemplazando al anterior.
     *
     * @return array{numeroSerie: string, emisor: string}
     */
    public function emitirCertificado(int $usuarioId, int $diasVigencia, string $pin): array
    {
        $titular = $this->usuarios->buscarConFacultad($usuarioId)
            ?? throw ReglaNegocioException::noEncontrado('El usuario no existe.');
        if (! in_array($titular->rol, ['VICERRECTOR', 'SUPLENTE'], true) && $titular->cargo !== 'DECANO') {
            throw new ReglaNegocioException('Solo el Vicerrector/a o un Decano habilitado como suplente reciben certificado de firma.');
        }

        $emitido = $this->firmador->emitirCertificado([
            'nombre' => trim("{$titular->nombres} {$titular->apellidos}"),
            'email' => $titular->email,
            'cargo' => $titular->cargo,
            'unidad' => $titular->rol === 'VICERRECTOR' ? 'Vicerrectorado' : ($titular->facultad ?? 'Decanato'),
            'diasVigencia' => $diasVigencia,
            'pin' => $pin,
        ]);

        $this->transaccion->ejecutar(fn () => $this->delegaciones->reemplazarCertificado([
            'usuario_id' => $usuarioId,
            'numero_serie' => $emitido['numeroSerie'],
            'emisor' => $emitido['emisor'],
            'fecha_emision' => $emitido['fechaEmision'],
            'fecha_expiracion' => $emitido['fechaExpiracion'],
        ]));

        return $emitido;
    }

    /**
     * PDF de la resolución que respalda la suplencia.
     *
     * @return array{ruta: string, nombre: string}
     */
    public function archivoResolucion(int $delegacionId): array
    {
        $delegacion = $this->delegaciones->buscar($delegacionId)
            ?? throw ReglaNegocioException::noEncontrado('La delegación no existe.');
        $ruta = $this->archivos->rutaEnDisco($delegacion->archivo_resolucion_url)
            ?? throw ReglaNegocioException::noEncontrado('El PDF de la resolución no está disponible en el servidor.');

        return ['ruta' => $ruta, 'nombre' => 'resolucion_'.preg_replace('/[^A-Za-z0-9-]/', '_', $delegacion->nro_resolucion).'.pdf'];
    }

    public function certificadoRaiz(): string
    {
        return $this->firmador->certificadoRaiz();
    }
}
