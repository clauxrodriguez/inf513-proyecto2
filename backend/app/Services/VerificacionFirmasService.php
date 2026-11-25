<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;

/**
 * Verifica las firmas incrustadas en un PDF con el verificador configurado (FIRMA_VERIFICADOR):
 * el microservicio local o el Validador Web de AGETIC. Comprueba también que una co-firma recibida
 * desde Jacobitus sea la esperada: una firma más que el PDF original, todas íntegras y la nueva
 * hecha con el certificado registrado del firmante.
 */
class VerificacionFirmasService
{
    public function __construct(
        private FirmadorService $firmador,
        private ValidadorAgeticService $agetic,
    ) {}

    /**
     * @return array{valido: bool, totalFirmas: int, hashSha256: string, firmas: list<array<string, mixed>>, verificador: string}
     */
    public function verificar(string $pdf): array
    {
        if (config('services.firma.verificador') === 'agetic') {
            return $this->agetic->verificar($pdf);
        }

        return ['verificador' => 'FIRMADOR', ...$this->firmador->verificar($pdf)];
    }

    /**
     * Valida la co-firma PAdES y devuelve los datos de la firma nueva.
     *
     * @return array<string, mixed>
     */
    public function exigirCofirma(string $original, string $firmado, string $numeroSerieEsperado, string $documento): array
    {
        if (! str_starts_with($firmado, '%PDF-')) {
            throw new ReglaNegocioException("El archivo firmado de «{$documento}» no es un PDF válido.");
        }

        $antes = $this->verificar($original)['totalFirmas'];
        $despues = $this->verificar($firmado);

        if ($despues['totalFirmas'] !== $antes + 1) {
            throw new ReglaNegocioException("«{$documento}» debe tener exactamente una firma nueva: tenía {$antes} y el PDF recibido tiene {$despues['totalFirmas']}.");
        }
        $alterada = collect($despues['firmas'])->first(fn (array $firma) => ! ($firma['integra'] ?? false));
        if ($alterada) {
            throw new ReglaNegocioException("En «{$documento}» la firma {$alterada['orden']} no está íntegra: el documento fue modificado después de firmarse.");
        }

        $nueva = collect($despues['firmas'])->last();
        if (! $this->mismaSerie((string) ($nueva['numeroSerie'] ?? ''), $numeroSerieEsperado)) {
            throw new ReglaNegocioException("La firma de «{$documento}» no corresponde a su certificado registrado (N° {$numeroSerieEsperado}).");
        }
        if (config('services.firma.exigir_confianza') && ! ($nueva['confiable'] ?? false)) {
            throw new ReglaNegocioException("El certificado que firmó «{$documento}» no pertenece a una Autoridad Certificadora de confianza.");
        }

        return $nueva;
    }

    /**
     * Compara números de serie aunque vengan en hexadecimal o en decimal, con o sin separadores.
     */
    public function mismaSerie(string $a, string $b): bool
    {
        $formas = fn (string $serie): array => array_filter(array_unique([
            $limpia = ltrim(strtolower(preg_replace('/[^0-9a-fA-F]/', '', $serie)), '0'),
            ctype_digit($serie) ? ltrim($this->decimalAHexadecimal($serie), '0') : null,
        ]));

        return $a !== '' && $b !== '' && array_intersect($formas($a), $formas($b)) !== [];
    }

    /**
     * Conversión de enteros decimales arbitrariamente largos a hexadecimal (sin bcmath ni gmp).
     */
    private function decimalAHexadecimal(string $decimal): string
    {
        $hexadecimal = '';
        while ($decimal !== '' && $decimal !== '0') {
            $resto = 0;
            $cociente = '';
            foreach (str_split($decimal) as $digito) {
                $actual = $resto * 10 + (int) $digito;
                $cociente .= intdiv($actual, 16);
                $resto = $actual % 16;
            }
            $hexadecimal = dechex($resto).$hexadecimal;
            $decimal = ltrim($cociente, '0');
        }

        return $hexadecimal === '' ? '0' : $hexadecimal;
    }
}
