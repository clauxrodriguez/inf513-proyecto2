<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    /** Prefijo de la ability del token que guarda el rol activo de la sesión. */
    public const PREFIJO_ROL = 'rol:';

    protected $table = 'usuario';

    public $timestamps = false;

    /**
     * Columna de la contraseña en la tabla usuario.
     *
     * @var string
     */
    protected $authPasswordName = 'password_hash';

    protected $fillable = [
        'nombres',
        'apellidos',
        'ci',
        'registro',
        'email',
        'telefono',
        'password_hash',
        'cargo',
        'activo',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'activo' => 'boolean',
            'fecha_creacion' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Rol, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'usuario_rol', 'usuario_id', 'rol_id');
    }

    /**
     * @return BelongsToMany<Carrera, $this>
     */
    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'usuario_carrera', 'usuario_id', 'carrera_id')
            ->withPivot('fecha_ingreso');
    }

    /**
     * $usuario->rol: rol activo de la sesión o, sin token (consola, pruebas), el primer rol asignado.
     *
     * @return Attribute<?string, never>
     */
    protected function rol(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->rolActivo() ?? $this->roles()->orderBy('rol.id')->value('codigo'));
    }

    /**
     * Rol con el que opera la sesión actual (guardado en las abilities del token de Sanctum).
     */
    public function rolActivo(): ?string
    {
        $token = $this->currentAccessToken();
        $abilities = $token instanceof PersonalAccessToken ? (array) $token->abilities : [];

        foreach ($abilities as $ability) {
            if (str_starts_with($ability, self::PREFIJO_ROL)) {
                return substr($ability, strlen(self::PREFIJO_ROL));
            }
        }

        return null;
    }
}
