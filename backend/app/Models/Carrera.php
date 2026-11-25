<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Carrera extends Model
{
    protected $table = 'carrera';

    public $timestamps = false;

    protected $fillable = [
        'facultad_id',
        'codigo',
        'nombre',
        'regimen',
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'usuario_carrera', 'carrera_id', 'usuario_id')
            ->withPivot('fecha_ingreso');
    }
}
