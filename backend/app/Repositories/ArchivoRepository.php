<?php

namespace App\Repositories;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Persistencia de archivos (PDF, imágenes, resoluciones) en el disco público.
 * Las rutas que se guardan en la BD tienen la forma /storage/...
 */
class ArchivoRepository
{
    private const PREFIJO = '/storage/';

    public function guardarSubido(UploadedFile $archivo, string $carpeta): ?string
    {
        $relativa = $archivo->store($carpeta, 'public');

        return $relativa ? self::PREFIJO.$relativa : null;
    }

    public function guardarContenido(string $carpeta, string $nombreArchivo, string $contenido): string
    {
        $relativa = trim($carpeta, '/').'/'.$nombreArchivo;
        Storage::disk('public')->put($relativa, $contenido);

        return self::PREFIJO.$relativa;
    }

    public function eliminar(?string $rutaPublica): void
    {
        if ($rutaPublica && str_starts_with($rutaPublica, self::PREFIJO)) {
            Storage::disk('public')->delete(substr($rutaPublica, strlen(self::PREFIJO)));
        }
    }

    /**
     * Ruta absoluta en disco de un archivo existente, o null si no existe.
     */
    public function rutaEnDisco(?string $rutaPublica): ?string
    {
        if (! $rutaPublica || ! str_starts_with($rutaPublica, self::PREFIJO)) {
            return null;
        }
        $ruta = Storage::disk('public')->path(substr($rutaPublica, strlen(self::PREFIJO)));

        return is_file($ruta) ? $ruta : null;
    }

    public function leer(string $rutaEnDisco): string
    {
        return file_get_contents($rutaEnDisco);
    }
}
