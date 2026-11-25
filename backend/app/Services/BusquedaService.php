<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\BusquedaRepository;

/**
 * Búsqueda global del encabezado: el estudiante busca solo en sus trámites; el personal, en todos.
 */
class BusquedaService
{
    public function __construct(private BusquedaRepository $busqueda) {}

    /**
     * @return array{tramites: mixed, certificados: mixed}
     */
    public function buscar(User $usuario, string $texto): array
    {
        $texto = trim($texto);
        $esEstudiante = $usuario->rol === 'ESTUDIANTE';

        return [
            'tramites' => $this->busqueda->tramites($texto, $esEstudiante ? $usuario->id : null),
            'certificados' => $esEstudiante ? [] : $this->busqueda->certificados($texto),
        ];
    }
}
