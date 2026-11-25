<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Repositories\AuditoriaRepository;
use App\Repositories\DelegacionRepository;
use App\Repositories\TramiteRepository;
use App\Repositories\UnidadDeTrabajo;
use App\Repositories\UsuarioRepository;

/**
 * CU2 - Delegación de autoridad (suplencia del Vicerrector por un Decano, según el orden de prelación
 * de las facultades). El panel muestra, solo como consulta, los certificados de firma que cada autoridad
 * registró desde su token: los emite una Entidad Certificadora autorizada (ADSIB, Digicert), no la universidad.
 */
class DelegacionService
{
    /** @var array<string, string> */
    private const UNIDAD_POR_ROL = [
        'VICERRECTOR' => 'Vicerrectorado',
        'RECTOR' => 'Rectorado',
        'ADMISIONES' => 'Admisiones y Registro',
        'DAGA' => 'DAGA / DICA',
    ];

    public function __construct(
        private UnidadDeTrabajo $transaccion,
        private DelegacionRepository $delegaciones,
        private UsuarioRepository $usuarios,
        private TramiteRepository $tramites,
        private AuditoriaRepository $auditoria,
        private FirmadorService $firmador,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function panel(): array
    {
        $decanos = $this->usuarios->decanosPorPrelacion();

        return [
            'delegaciones' => $this->delegaciones->listar(),
            'vicerrectores' => $this->usuarios->conRol('VICERRECTOR'),
            'suplentes' => $decanos,
            'suplente_estatutario_id' => $decanos->first()?->id,
            'certificados' => $this->delegaciones->listarCertificados(),
            'indicadores' => $this->tramites->indicadores(),
        ];
    }

    /**
     * Registra la suplencia. Corresponde el Decano de la facultad más antigua (orden de prelación);
     * designar a otro exige justificar en el detalle por qué los anteriores están impedidos.
     *
     * @param  array{titular_id: int, suplente_id: int, nro_resolucion: string, fecha_resolucion: string, motivo: string, detalle_motivo?: ?string, fecha_inicio: string, fecha_fin: string}  $datos
     */
    public function registrar(array $datos, int $registradoPor): int
    {
        if (! in_array('VICERRECTOR', $this->usuarios->codigosDeRol($datos['titular_id']), true)) {
            throw new ReglaNegocioException('El titular de la delegación debe tener el rol de Vicerrector/a.');
        }
        $decanos = $this->usuarios->decanosPorPrelacion();
        if (! $decanos->contains('id', $datos['suplente_id'])) {
            throw new ReglaNegocioException('El suplente debe ser un Decano/a activo/a con facultad asignada (Estatuto Orgánico, Art. 46).');
        }
        $estatutario = $decanos->first();
        $detalle = trim((string) ($datos['detalle_motivo'] ?? ''));
        if ((int) $estatutario->id !== (int) $datos['suplente_id'] && mb_strlen($detalle) < 10) {
            throw new ReglaNegocioException(
                "Por orden de prelación corresponde {$estatutario->nombres} {$estatutario->apellidos} ({$estatutario->facultad}). "
                .'Para designar a otro Decano, justifique en el detalle el impedimento de los anteriores.'
            );
        }
        if ($datos['fecha_resolucion'] > $datos['fecha_inicio']) {
            throw new ReglaNegocioException('La resolución debe ser anterior o igual al inicio de la suplencia.');
        }
        if ($this->delegaciones->haySuperpuesta($datos['titular_id'], $datos['fecha_inicio'], $datos['fecha_fin'])) {
            throw new ReglaNegocioException('Ya existe una delegación ACTIVA del titular que se superpone con ese periodo.');
        }

        return $this->delegaciones->crear([
            ...$datos,
            'detalle_motivo' => $detalle !== '' ? $detalle : null,
            'estado' => 'ACTIVA',
            'registrado_por' => $registradoPor,
        ]);
    }

    /**
     * Revoca la suplencia; el motivo queda en la bitácora inalterable.
     */
    public function revocar(int $id, string $motivo): void
    {
        $delegacion = $this->delegaciones->buscar($id) ?? throw ReglaNegocioException::noEncontrado('La delegación no existe.');
        if ($delegacion->estado !== 'ACTIVA') {
            throw new ReglaNegocioException("La delegación ya está {$delegacion->estado}.");
        }

        $this->transaccion->ejecutar(function () use ($id, $delegacion, $motivo): void {
            $this->delegaciones->actualizar($id, ['estado' => 'REVOCADA']);
            $this->auditoria->registrarEvento(null, 'REVOCAR_DELEGACION', 'delegacion_autoridad', $id, [
                'nro_resolucion' => $delegacion->nro_resolucion,
                'motivo' => trim($motivo),
            ]);
        });
    }

    /**
     * SOLO DESARROLLO (comando certificados:prueba): emite un certificado con la AC de prueba del firmador
     * para el modo simulación, sin token. En producción el certificado lo emite la Entidad Certificadora y
     * la autoridad lo registra al firmar con Jacobitus (FirmaDigitalService::registrarCertificado).
     *
     * @return array{numeroSerie: string, emisor: string}
     */
    public function emitirCertificado(int $usuarioId, int $diasVigencia, string $pin): array
    {
        $titular = $this->usuarios->firmantes()->firstWhere('id', $usuarioId)
            ?? throw new ReglaNegocioException('Solo las autoridades firmantes (Carrera, Decanato, Admisiones, DAGA, Vicerrectorado y Rectorado) reciben certificado de firma.');
        $roles = explode(', ', (string) $titular->roles);
        $rolFirmante = collect(UsuarioRepository::ROLES_FIRMANTES)->first(fn (string $rol) => in_array($rol, $roles, true));

        $emitido = $this->firmador->emitirCertificado([
            'nombre' => trim("{$titular->nombres} {$titular->apellidos}"),
            'email' => $titular->email,
            'cargo' => $titular->cargo,
            'unidad' => self::UNIDAD_POR_ROL[$rolFirmante] ?? ($titular->facultad ?? 'UAGRM'),
            'diasVigencia' => $diasVigencia,
            'pin' => $pin,
        ]);

        $this->transaccion->ejecutar(fn () => $this->delegaciones->reemplazarCertificado([
            'usuario_id' => $usuarioId,
            'numero_serie' => $emitido['numeroSerie'],
            'emisor' => $emitido['emisor'],
            'fecha_emision' => $emitido['fechaEmision'],
            'fecha_expiracion' => $emitido['fechaExpiracion'],
        ]));

        return $emitido;
    }
}
