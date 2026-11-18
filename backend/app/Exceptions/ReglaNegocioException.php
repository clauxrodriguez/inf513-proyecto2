<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de la capa de negocio. Los servicios la lanzan sin conocer HTTP;
 * el manejador global (bootstrap/app.php) la convierte en {"message": "..."} con su código.
 */
class ReglaNegocioException extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $estado = 422)
    {
        parent::__construct($mensaje);
    }

    public static function noEncontrado(string $mensaje): self
    {
        return new self($mensaje, 404);
    }

    public static function prohibido(string $mensaje = 'No tiene permisos para realizar esta acción.'): self
    {
        return new self($mensaje, 403);
    }

    public static function servicioNoDisponible(string $mensaje): self
    {
        return new self($mensaje, 503);
    }
}
