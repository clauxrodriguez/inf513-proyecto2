<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\User;
use App\Repositories\ArchivoRepository;
use App\Repositories\DelegacionRepository;
use App\Repositories\UnidadDeTrabajo;
use App\Repositories\UsuarioRepository;
use Illuminate\Support\Collection;

/**
 * Firma con Jacobitus (token o softoken del firmante, en su propia PC): el backend entrega los PDF a firmar,
 * recibe los PDF co-firmados, verifica cada co-firma PAdES y la registra en la etapa que corresponda
 * (ORIGEN, DESPACHO o RECTORADO), con las mismas transiciones y notificaciones que la firma en el servidor.
 */
class FirmaDigitalService
{
    public const ETAPAS = ['ORIGEN', 'DESPACHO', 'RECTORADO'];

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private OrigenService $origen,
        private DespachoService $despacho,
        private VerificacionFirmasService $verificacion,
        private DelegacionRepository $delegaciones,
        private UsuarioRepository $usuarios,
        private ArchivoRepository $archivos,
    ) {}

    /**
     * PDF a firmar (Base64) con su SHA-256, y los datos del firmante y del certificado esperado.
     *
     * @param  list<int>  $ids  documentos (ORIGEN) o trámites (DESPACHO / RECTORADO)
     * @return array{etapa: string, firmante: array<string, mixed>, documentos: list<array<string, mixed>>}
     */
    public function preparar(User $usuario, string $etapa, array $ids): array
    {
        [$habilitacion, $elementos] = $this->elementos($usuario, $etapa, $ids);

        return [
            'etapa' => $etapa,
            'firmante' => [
                'nombre' => trim("{$usuario->nombres} {$usuario->apellidos}"),
                'leyenda' => $habilitacion['leyenda'],
                'razon' => $habilitacion['razon'] ?? $habilitacion['leyenda'],
                'numero_serie' => $habilitacion['certificado']['numero_serie'],
            ],
            'documentos' => $elementos->map(function (array $elemento): array {
                $pdf = $this->archivos->leer($elemento['ruta']);

                return [
                    'id' => $elemento['id'],
                    'etiqueta' => $elemento['etiqueta'],
                    'pdf_base64' => base64_encode($pdf),
                    'hash_sha256' => hash('sha256', $pdf),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Verifica y registra en un solo lote los PDF firmados por Jacobitus.
     *
     * @param  list<array{id: int, pdf_base64: string}>  $firmados
     * @return array{total: int, lote_id?: int, ingresados?: list<string>}
     */
    public function confirmarLote(User $usuario, string $etapa, array $firmados, bool $esRemota, ?string $ubicacion, ?string $ipOrigen): array
    {
        $ids = array_map(fn (array $f) => (int) $f['id'], $firmados);
        [$habilitacion, $elementos] = $this->elementos($usuario, $etapa, $ids);
        $porId = collect($firmados)->keyBy(fn (array $f) => (int) $f['id']);
        $maximo = config('services.firma.max_pdf_kb') * 1024;

        $verificados = $elementos->map(function (array $elemento) use ($porId, $habilitacion, $maximo): array {
            $pdf = base64_decode((string) $porId->get($elemento['id'])['pdf_base64'], true);
            if ($pdf === false || $pdf === '') {
                throw new ReglaNegocioException("El PDF firmado de «{$elemento['etiqueta']}» no tiene un formato Base64 válido.");
            }
            if (strlen($pdf) > $maximo) {
                throw new ReglaNegocioException("El PDF firmado de «{$elemento['etiqueta']}» supera el tamaño máximo permitido.");
            }

            $nueva = $this->verificacion->exigirCofirma(
                $this->archivos->leer($elemento['ruta']), $pdf, $habilitacion['certificado']['numero_serie'], $elemento['etiqueta'],
            );

            return [
                'objeto' => $elemento['objeto'],
                'pdf' => $pdf,
                'hash' => hash('sha256', $pdf),
                // Constancia de la co-firma verificada (Jacobitus no entrega el CMS por separado)
                'firma' => json_encode([
                    'origen' => 'JACOBITUS',
                    'firmante' => $nueva['firmante'] ?? null,
                    'numero_serie' => $nueva['numeroSerie'] ?? null,
                    'emisor' => $nueva['emisor'] ?? null,
                    'fecha' => $nueva['fecha'] ?? null,
                    'confiable' => $nueva['confiable'] ?? null,
                ], JSON_UNESCAPED_UNICODE),
            ];
        })->values();

        if ($etapa === 'ORIGEN') {
            $resultado = $this->origen->registrarFirmas($usuario, $habilitacion,
                $verificados->map(fn (array $v) => ['documento' => $v['objeto'], 'pdf' => $v['pdf'], 'hash' => $v['hash'], 'firma' => $v['firma']])->all(),
                $esRemota, $this->origen->ubicacion($esRemota, $ubicacion), $ipOrigen);

            return $resultado;
        }

        $loteId = $this->despacho->registrarFirmas($usuario, $habilitacion, $etapa,
            $verificados->map(fn (array $v) => ['tramite' => $v['objeto'], 'pdf' => $v['pdf'], 'hash' => $v['hash'], 'firma' => $v['firma']])->all(),
            $esRemota, $this->despacho->ubicacion($etapa, $esRemota, $ubicacion), $ipOrigen);

        return ['total' => $verificados->count(), 'lote_id' => $loteId];
    }

    /**
     * El firmante registra el certificado de su token o softoken (leído por Jacobitus) como su certificado activo.
     *
     * @param  array{numero_serie: string, emisor: string, fecha_emision: string, fecha_expiracion: string}  $datos
     */
    public function registrarCertificado(User $usuario, array $datos): void
    {
        $roles = $this->usuarios->codigosDeRol($usuario->id);
        if (array_intersect($roles, UsuarioRepository::ROLES_FIRMANTES) === []) {
            throw ReglaNegocioException::prohibido('Solo las autoridades firmantes registran certificados de firma.');
        }
        if ($datos['fecha_expiracion'] < now()->toDateString()) {
            throw new ReglaNegocioException('El certificado del token está vencido.');
        }

        $this->transaccion->ejecutar(fn () => $this->delegaciones->reemplazarCertificado([
            'usuario_id' => $usuario->id,
            'numero_serie' => mb_substr(trim($datos['numero_serie']), 0, 80),
            'emisor' => mb_substr(trim($datos['emisor']), 0, 150),
            'fecha_emision' => $datos['fecha_emision'],
            'fecha_expiracion' => $datos['fecha_expiracion'],
        ]));
    }

    /**
     * Habilitación del firmante y elementos a firmar de la etapa, en el orden pedido.
     *
     * @param  list<int>  $ids
     * @return array{0: array<string, mixed>, 1: Collection<int, array{id: int, etiqueta: string, ruta: string, objeto: object}>}
     */
    private function elementos(User $usuario, string $etapa, array $ids): array
    {
        if ($etapa === 'ORIGEN') {
            $habilitacion = $this->origen->exigirHabilitacion($usuario);
            $elementos = $this->origen->documentosParaFirmar($usuario, $ids)->map(fn (object $documento) => [
                'id' => (int) $documento->id,
                'etiqueta' => "{$documento->tramite_codigo} · {$documento->documento_nombre} (firma {$documento->orden_pendiente}/{$documento->total_firmas})",
                'ruta' => $this->archivos->rutaEnDisco($documento->archivo_ruta),
                'objeto' => $documento,
            ]);
        } else {
            $habilitacion = $this->despacho->habilitacionPara($usuario, $etapa, exigir: true);
            $elementos = $this->despacho->tramitesParaFirmar($etapa, $ids)->map(fn (object $tramite) => [
                'id' => (int) $tramite->id,
                'etiqueta' => "{$tramite->codigo} · expediente",
                'ruta' => $this->despacho->documentoParaVisualizar($tramite->id)['ruta'],
                'objeto' => $tramite,
            ]);
        }

        return [$habilitacion, $elementos->sortBy(fn (array $e) => array_search($e['id'], $ids, true))->values()];
    }
}
