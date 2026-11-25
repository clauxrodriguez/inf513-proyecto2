<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Cliente del Validador Web de firma digital de AGETIC (API REST configurada en AGETIC_VALIDADOR_URL).
 * Envía el PDF en Base64 y normaliza la respuesta al mismo formato que FirmadorService::verificar:
 * {valido, totalFirmas, hashSha256, firmas: [{orden, firmante, numeroSerie, emisor, fecha, integra, confiable}]}.
 * Los nombres de campo de la respuesta se resuelven de forma tolerante en campo(); si la API usa otros,
 * se agregan ahí.
 */
class ValidadorAgeticService
{
    public function configurado(): bool
    {
        return filled(config('services.agetic_validador.url'));
    }

    /**
     * @return array{valido: bool, totalFirmas: int, hashSha256: string, firmas: list<array<string, mixed>>, verificador: string}
     */
    public function verificar(string $pdf): array
    {
        if (! $this->configurado()) {
            throw ReglaNegocioException::servicioNoDisponible('El Validador Web de AGETIC no está configurado (AGETIC_VALIDADOR_URL).');
        }

        try {
            $respuesta = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(config('services.agetic_validador.timeout'))
                ->post(config('services.agetic_validador.url'), [
                    config('services.agetic_validador.campo_archivo') => base64_encode($pdf),
                ]);
        } catch (ConnectionException) {
            throw ReglaNegocioException::servicioNoDisponible('No se pudo conectar con el Validador Web de AGETIC.');
        }

        if ($respuesta->failed()) {
            throw new ReglaNegocioException('El Validador de AGETIC rechazó el documento: '.($this->campo((array) $respuesta->json(), ['mensaje', 'message', 'error']) ?? 'error '.$respuesta->status()), 502);
        }

        $datos = (array) $respuesta->json();
        $lista = $this->campo($datos, ['firmas', 'signatures', 'datos.firmas', 'data.firmas', 'datos.signatures', 'data.signatures', 'resultado.firmas']) ?? [];

        $firmas = collect(array_values((array) $lista))->map(fn ($firma, int $indice) => [
            'orden' => $indice + 1,
            'firmante' => $this->campo((array) $firma, ['firmante', 'nombre', 'commonName', 'common_name', 'signer', 'titular.nombre', 'certificado.titular']),
            'numeroSerie' => (string) ($this->campo((array) $firma, ['numeroSerie', 'serialNumber', 'serial_number', 'nro_serie', 'serie', 'certificado.numeroSerie', 'certificado.serialNumber']) ?? ''),
            'emisor' => $this->campo((array) $firma, ['emisor', 'issuer', 'certificado.emisor', 'certificado.issuer']),
            'fecha' => $this->campo((array) $firma, ['fecha', 'fechaFirma', 'fecha_firma', 'signingTime', 'date']),
            'integra' => (bool) ($this->campo((array) $firma, ['integra', 'integridad', 'valida', 'valid', 'firmaValida', 'esValida', 'noModificado']) ?? false),
            'confiable' => (bool) ($this->campo((array) $firma, ['confiable', 'cadenaConfianza', 'certificadoConfiable', 'trusted', 'confianza']) ?? false),
        ])->all();

        $validoGlobal = $this->campo($datos, ['valido', 'valid', 'datos.valido', 'data.valid', 'resultado.valido']);

        return [
            'valido' => $validoGlobal !== null ? (bool) $validoGlobal : ($firmas !== [] && collect($firmas)->every(fn (array $f) => $f['integra'])),
            'totalFirmas' => count($firmas),
            'hashSha256' => hash('sha256', $pdf),
            'firmas' => $firmas,
            'verificador' => 'AGETIC',
        ];
    }

    /**
     * Primer valor presente entre varias rutas posibles (notación con punto).
     *
     * @param  array<string, mixed>  $datos
     * @param  list<string>  $rutas
     */
    private function campo(array $datos, array $rutas): mixed
    {
        foreach ($rutas as $ruta) {
            $valor = Arr::get($datos, $ruta);
            if ($valor !== null) {
                return $valor;
            }
        }

        return null;
    }
}
