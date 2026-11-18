<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $table = 'usuario';

    public $timestamps = false;

    protected $fillable = [
        'nombres',
        'apellidos',
        'ci',
        'registro',
        'email',
        'telefono',
        'direccion',
        'password',
        'rol',
        'carrera_id',
        'cargo',
        'activo',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'activo' => 'boolean',
        ];
    }
}
