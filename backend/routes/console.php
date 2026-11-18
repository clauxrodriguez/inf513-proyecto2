<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CU7: verificación periódica de alertas SLA (ejecutar el planificador con `php artisan schedule:work`
// en desarrollo, o con un cron `* * * * * php artisan schedule:run` en el servidor)
Schedule::command('sla:verificar')->everyFifteenMinutes()->withoutOverlapping();
