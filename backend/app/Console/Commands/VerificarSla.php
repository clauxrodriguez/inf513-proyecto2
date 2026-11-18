<?php

namespace App\Console\Commands;

use App\Services\SlaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * CU7: verificación periódica del SLA de 72 h hábiles (programada en routes/console.php).
 */
#[Signature('sla:verificar')]
#[Description('Genera las alertas SLA preventivas (75 %) y vencidas (100 %) y notifica a Recepción')]
class VerificarSla extends Command
{
    public function handle(SlaService $sla): int
    {
        $nuevas = $sla->verificarAlertas();
        $this->info("Verificación SLA completada: {$nuevas} alerta(s) nueva(s).");

        return self::SUCCESS;
    }
}
