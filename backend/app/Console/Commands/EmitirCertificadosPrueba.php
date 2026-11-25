<?php

namespace App\Console\Commands;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\UsuarioRepository;
use App\Services\DelegacionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Emite con la AC de prueba (microservicio /firmador) un certificado de firma para cada autoridad firmante,
 * con un PIN común. Solo para demostración y pruebas locales.
 */
#[Signature('certificados:prueba {--pin=123456 : PIN de los certificados (mínimo 6 caracteres)} {--dias=365 : Días de vigencia}')]
#[Description('Emite certificados digitales de prueba para todos los usuarios con rol firmante')]
class EmitirCertificadosPrueba extends Command
{
    public function handle(UsuarioRepository $usuarios, DelegacionService $delegaciones): int
    {
        $pin = (string) $this->option('pin');
        if (mb_strlen($pin) < 6) {
            $this->error('El PIN debe tener al menos 6 caracteres.');

            return self::FAILURE;
        }

        foreach ($usuarios->firmantes() as $firmante) {
            try {
                $emitido = $delegaciones->emitirCertificado($firmante->id, (int) $this->option('dias'), $pin);
                $this->info("{$firmante->nombres} {$firmante->apellidos} ({$firmante->roles}): certificado {$emitido['numeroSerie']}");
            } catch (ReglaNegocioException $e) {
                $this->error("{$firmante->nombres} {$firmante->apellidos}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info("Listo. PIN de todos los certificados: {$pin}");

        return self::SUCCESS;
    }
}
