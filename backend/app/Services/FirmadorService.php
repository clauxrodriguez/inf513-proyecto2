<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente del microservicio Java de firma digital (carpeta /firmador): integración externa de la capa de negocio.
 * Se comunica solo por localhost con la clave compartida FIRMADOR_API_KEY.
 */
class FirmadorService
{
    /**
     * Emite un certificado de la AC de prueba (el equivalente académico de ADSIB).
     *
     * @param  array{nombre: string, email: ?string, cargo: ?string, unidad: ?string, diasVigencia: int, pin: string}  $solicitud
     * @return array{numeroSerie: string, emisor: string, sujeto: string, fechaEmision: string, fechaExpiracion: string, certificadoPem: string}
     */
    public function emitirCertificado(array $solicitud): array
    {
        return $this->procesar(fn () => $this->cliente()->asJson()->post('/certificados', $solicitud))->json();
    }

    /**
     * Firma PAdES del legajo con el certificado del firmante (abierto con su PIN en el microservicio).
     *
     * @param  array{numeroSerie: string, pin: string, nombre: string, razon: string, ubicacion: ?string, contacto: ?string, leyenda: ?string}  $datos
     * @return array{pdfFirmado: string, hashDocumento: string, hashFirmado: string, firmaCms: string, algoritmo: string, numeroSerieCertificado: string, fechaFirma: string}
     */
    public function firmar(string $pdf, array $datos): array
    {
        $respuesta = $this->procesar(fn () => $this->cliente()
            ->attach('pdf', $pdf, 'legajo.pdf', ['Content-Type' => 'application/pdf'])
            ->post('/firmas', array_filter($datos, fn ($valor) => $valor !== null))
        )->json();
        $respuesta['pdfFirmado'] = base64_decode($respuesta['pdfFirmado']);

        return $respuesta;
    }

    /**
     * SF-2: sello institucional de Rectorado, agregado como firma incremental.
     *
     * @param  array{nombre: string, razon: string, ubicacion: ?string, contacto: ?string, leyenda: ?string}  $datos
     * @return array{pdfFirmado: string, hashDocumento: string, hashFirmado: string, firmaCms: string}
     */
    public function sellar(string $pdf, array $datos): array
    {
        $respuesta = $this->procesar(fn () => $this->cliente()
            ->attach('pdf', $pdf, 'legajo_firmado.pdf', ['Content-Type' => 'application/pdf'])
            ->post('/sellos', array_filter($datos, fn ($valor) => $valor !== null))
        )->json();
        $respuesta['pdfFirmado'] = base64_decode($respuesta['pdfFirmado']);

        return $respuesta;
    }

    /**
     * CU6: certificado oficial / constancia en PDF/A-2b con QR, firmado con el sello electrónico del Vicerrectorado.
     *
     * @param  array<string, mixed>  $datos  Ver DatosCertificadoOficial en el microservicio
     * @return array{pdfFirmado: string, hashFirmado: string}
     */
    public function generarCertificadoOficial(array $datos): array
    {
        $respuesta = $this->procesar(fn () => $this->cliente()->asJson()->post('/certificados-oficiales', $datos))->json();
        $respuesta['pdfFirmado'] = base64_decode($respuesta['pdfFirmado']);

        return $respuesta;
    }

    /**
     * Verifica integridad, cadena de confianza y cobertura de cada firma del PDF.
     *
     * @return array{valido: bool, totalFirmas: int, hashSha256: string, firmas: array<int, array<string, mixed>>}
     */
    public function verificar(string $pdf): array
    {
        return $this->procesar(fn () => $this->cliente()
            ->attach('pdf', $pdf, 'documento.pdf', ['Content-Type' => 'application/pdf'])
            ->post('/verificaciones')
        )->json();
    }

    /**
     * Compila el legajo foliado (carátula + documentos) a partir de los archivos del trámite.
     *
     * @param  array<string, mixed>  $metadatos
     * @param  array<int, string>  $rutasArchivos  Rutas absolutas en disco
     * @return array{pdf: string, totalFojas: int, hash: string}
     */
    public function compilarLegajo(array $metadatos, array $rutasArchivos): array
    {
        $respuesta = $this->procesar(function () use ($metadatos, $rutasArchivos) {
            $cliente = $this->cliente();
            foreach ($rutasArchivos as $ruta) {
                $cliente->attach('archivos[]', file_get_contents($ruta), basename($ruta));
            }

            return $cliente->post('/legajos', [
                'metadatos' => json_encode($metadatos, JSON_UNESCAPED_UNICODE),
            ]);
        });

        return [
            'pdf' => $respuesta->body(),
            'totalFojas' => (int) $respuesta->header('X-Total-Fojas'),
            'hash' => $respuesta->header('X-Hash-Sha256'),
        ];
    }

    private function cliente(): PendingRequest
    {
        return Http::baseUrl(config('services.firmador.url'))
            ->withHeaders(['X-Api-Key' => config('services.firmador.api_key')])
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(config('services.firmador.timeout'));
    }

    /**
     * Ejecuta la llamada y traduce los errores del microservicio a respuestas HTTP para el SPA.
     *
     * @param  callable(): Response  $llamada
     */
    private function procesar(callable $llamada): Response
    {
        try {
            $respuesta = $llamada();
        } catch (ConnectionException) {
            throw ReglaNegocioException::servicioNoDisponible('El microservicio de firma digital no está disponible. Inícielo desde la carpeta firmador (mvnw spring-boot:run).');
        }

        if ($respuesta->failed()) {
            $mensaje = $respuesta->json('message') ?? 'El microservicio de firma rechazó la solicitud.';
            throw new ReglaNegocioException($mensaje, $respuesta->clientError() ? 422 : 502);
        }

        return $respuesta;
    }
}
