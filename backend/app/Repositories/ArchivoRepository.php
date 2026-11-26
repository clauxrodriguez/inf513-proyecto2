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

    /**
     * Cuenta automáticamente las páginas de un archivo PDF en disco.
     */
    public function contarPaginasPdf(?string $rutaEnDisco): int
    {
        if (! $rutaEnDisco || ! is_file($rutaEnDisco)) {
            return 1;
        }
        $contenido = @file_get_contents($rutaEnDisco);
        if ($contenido === false || $contenido === '') {
            return 1;
        }

        // 1. Contar objetos de página individuales (/Type /Page sin incluir /Type /Pages)
        $paginas = preg_match_all('/\/Type\s*\/Page(?!s)\b/i', $contenido);
        if ($paginas > 0) {
            return min(500, $paginas);
        }

        // 2. Respaldo: nodo raíz del árbol de páginas (/Type /Pages ... /Count N)
        if (preg_match_all('/\/Count\s+(\d+)/i', $contenido, $coincidencias) && ! empty($coincidencias[1])) {
            $maximo = max(array_map('intval', $coincidencias[1]));
            if ($maximo > 0) {
                return min(500, $maximo);
            }
        }

        return 1;
    }
}
