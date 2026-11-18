<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\ArchivoRepository;
use App\Repositories\CatalogoRepository;
use App\Repositories\ExpedienteRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use App\Repositories\UsuarioRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Throwable;

/**
 * CU3 - Gestión de trámites, selección de documentos, validación de requisitos y compilación del expediente.
 */
class TramiteService
{
    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private TramiteRepository $tramites,
        private CatalogoRepository $catalogos,
        private UsuarioRepository $usuarios,
        private ExpedienteRepository $expedientes,
        private ArchivoRepository $archivos,
    ) {}

    /**
     * @return array{documentos: Collection, requisitos: Collection, carreras: Collection, estudiantes: Collection|array}
     */
    public function catalogos(User $usuario): array
    {
        $firmas = $this->catalogos->firmasPrecedentes();
        $documentos = $this->catalogos->documentosActivos()->transform(function ($documento) use ($firmas) {
            $documento->firmas_precedentes = $firmas->get($documento->id, collect())->values();

            return $documento;
        });

        return [
            'documentos' => $documentos,
            'requisitos' => $this->catalogos->requisitos(),
            'carreras' => $this->catalogos->carreras(),
            'estudiantes' => $usuario->rol === 'ESTUDIANTE' ? [] : $this->usuarios->estudiantesActivos(),
        ];
    }

    /**
     * El estudiante solo ve sus propios trámites.
     */
    public function listar(User $usuario, ?string $estado): Collection
    {
        $tramites = $this->tramites->listar($usuario->rol === 'ESTUDIANTE' ? $usuario->id : null, $estado);
        $ids = $tramites->pluck('id');

        $documentos = $this->tramites->documentosDe($ids);
        $descuentos = $this->tramites->descuentosDe($ids);
        $expedientes = $this->expedientes->vigentesDe($ids);
        $requisitos = $this->tramites->requisitosDe($ids);

        return $tramites->transform(function ($tramite) use ($documentos, $descuentos, $expedientes, $requisitos) {
            $tramite->documentos = $documentos->get($tramite->id, collect())->values();
            $tramite->descuento = $descuentos->get($tramite->id);
            $tramite->expediente = $expedientes->get($tramite->id);
            $tramite->requisitos = $requisitos->get($tramite->id, collect())->values();

            return $tramite;
        });
    }

    /**
     * Registra el trámite, sus documentos (o la solicitud de descuento) y compila el expediente v1.
     * Si lo registra RECEPCION en ventanilla, avanza directo a EN_REVISION.
     *
     * @param  array<string, mixed>  $datos
     * @param  array<int, UploadedFile>  $archivos
     */
    public function registrar(User $usuario, array $datos, array $archivos): object
    {
        $solicitanteId = $usuario->rol === 'ESTUDIANTE' ? $usuario->id : (int) $datos['solicitante_id'];
        $carreraId = $datos['carrera_id'] ?? $this->usuarios->buscar($solicitanteId)?->carrera_id;
        if (! $carreraId) {
            throw new ReglaNegocioException('El estudiante solicitante debe tener una carrera asignada.');
        }

        $guardados = [];
        try {
            return $this->transaccion->ejecutar(function () use ($usuario, $datos, $archivos, $solicitanteId, $carreraId, &$guardados) {
                // El trigger genera el código (LEG-AAAA-000001 / DES-AAAA-000002) y asigna los requisitos
                $tramiteId = $this->tramites->crear([
                    'tipo' => $datos['tipo'],
                    'solicitante_id' => $solicitanteId,
                    'carrera_id' => $carreraId,
                    'destino' => $datos['destino'],
                    'pais_destino' => $datos['destino'] === 'EXTERIOR' ? $datos['pais_destino'] : null,
                    'institucion_destino' => $datos['institucion_destino'] ?? null,
                    'finalidad' => $datos['finalidad'] ?? null,
                    'prioridad' => $datos['prioridad'] ?? 'NORMAL',
                    'registrado_por' => $usuario->id,
                ]);
                $tramite = $this->tramites->buscar($tramiteId);
                $carpeta = "expedientes/{$tramite->codigo}";

                if ($datos['tipo'] === 'LEGALIZACION') {
                    foreach ($datos['documentos'] as $indice => $documento) {
                        $ruta = isset($archivos[$indice]) ? $this->archivos->guardarSubido($archivos[$indice], $carpeta) : null;
                        if ($ruta) {
                            $guardados[] = $ruta;
                        }
                        $nroValorado = ! empty($documento['nro_valorado']) ? trim($documento['nro_valorado']) : null;

                        $this->tramites->agregarDocumento([
                            'tramite_id' => $tramiteId,
                            'catalogo_documento_id' => $documento['catalogo_documento_id'],
                            'cantidad_paginas' => (int) $documento['cantidad_paginas'],
                            'cantidad_materias' => $documento['cantidad_materias'] ?? null,
                            'emitido_por_uagrm' => $documento['emitido_por_uagrm'] ?? true,
                            // Solo Recepción verifica las firmas precedentes en ventanilla (RN-04)
                            'firmas_verificadas' => $usuario->rol === 'RECEPCION' && (bool) ($documento['firmas_verificadas'] ?? false),
                            'nro_valorado' => $nroValorado,
                            'importe_valorado' => $nroValorado !== null ? ($documento['importe_valorado'] ?? null) : null,
                            'archivo_ruta' => $ruta,
                        ]);
                    }
                    $totalFojas = max(1, $this->tramites->asignarFolios($tramiteId));
                    $rutaExpediente = $guardados[0] ?? null;
                } else {
                    // trg_descuento cataloga CON_FINANCIAMIENTO / SIN_FINANCIAMIENTO (RN-02, RN-03)
                    $descuento = $datos['descuento'];
                    $this->tramites->agregarDescuento([
                        'tramite_id' => $tramiteId,
                        'unidad_postgrado' => $descuento['unidad_postgrado'],
                        'nombre_diplomado' => $descuento['nombre_diplomado'],
                        'gestion_diplomado' => $descuento['gestion_diplomado'],
                        'fecha_inicio_diplomado' => $descuento['fecha_inicio_diplomado'] ?? null,
                        'nro_avance_academico' => $descuento['nro_avance_academico'],
                        'regimen' => $descuento['regimen'],
                        'anio_ultima_nota' => (int) $descuento['anio_ultima_nota'],
                        'periodo_ultima_nota' => $descuento['regimen'] === 'SEMESTRAL' ? (int) $descuento['periodo_ultima_nota'] : null,
                        'modulo_reprobado' => $descuento['modulo_reprobado'] ?? null,
                    ]);
                    $totalFojas = max(1, count($archivos));
                    $rutaExpediente = null;
                    if ($archivos) {
                        $rutaExpediente = $this->archivos->guardarSubido(reset($archivos), $carpeta);
                        $guardados[] = $rutaExpediente;
                    }
                }

                $this->expedientes->crear([
                    'tramite_id' => $tramiteId,
                    'ruta_pdf' => $rutaExpediente ?? "/storage/{$carpeta}/expediente_v1.pdf",
                    'total_fojas' => $totalFojas,
                    'hash_sha256' => hash('sha256', $tramite->codigo.'|'.now()->timestamp),
                    'compilado_por' => $usuario->id,
                ]);

                if ($usuario->rol === 'RECEPCION') {
                    $this->tramites->cambiarEstado($tramiteId, 'EN_REVISION');
                }

                return $this->tramites->buscar($tramiteId);
            });
        } catch (Throwable $e) {
            foreach ($guardados as $ruta) {
                $this->archivos->eliminar($ruta);
            }
            throw $e;
        }
    }

    /**
     * Recepción valida el valorado de caja y las firmas precedentes de un documento.
     *
     * @param  array{firmas_verificadas: bool, nro_valorado?: ?string, importe_valorado?: float|string|null}  $datos
     */
    public function validarDocumento(int $documentoId, array $datos): void
    {
        $documento = $this->tramites->buscarDocumento($documentoId)
            ?? throw ReglaNegocioException::noEncontrado('El documento no existe.');

        $this->tramites->actualizarDocumento($documentoId, [
            'firmas_verificadas' => $datos['firmas_verificadas'],
            'nro_valorado' => $datos['nro_valorado'] ?? $documento->nro_valorado,
            'importe_valorado' => $datos['importe_valorado'] ?? $documento->importe_valorado,
            'estado' => $datos['firmas_verificadas'] ? 'VALIDO' : 'PENDIENTE',
        ]);
        $this->tramites->cambiarEstado($documento->tramite_id, 'EN_REVISION', soloSiEstado: 'INGRESADO');
    }
}
