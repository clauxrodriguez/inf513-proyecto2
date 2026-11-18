<?php

namespace App\Repositories;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Transacción de base de datos que la capa de negocio usa para agrupar varias operaciones de repositorios:
 * se confirman todas o ninguna (ROLLBACK ante cualquier error).
 */
class UnidadDeTrabajo
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operaciones
     * @return T
     */
    public function ejecutar(Closure $operaciones): mixed
    {
        return DB::transaction($operaciones);
    }
}
