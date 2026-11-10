<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario global (tabla «users» del kit, sin institucion_id): una persona puede
 * pertenecer a varios colegios por medio de «membresias».
 */
#[Fillable(['nombres', 'apellidos', 'tipo_documento', 'numero_documento', 'email', 'celular', 'password'])]
#[Hidden(['password', 'dos_pasos_secreto', 'dos_pasos_respaldo'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** La tabla del kit no tiene «remember_token»: se desactiva «recordarme». */
    protected $rememberTokenName = '';

    protected function casts(): array
    {
        return [
            'email_verificado_at' => 'datetime',
            'ultimo_acceso_at' => 'datetime',
            'es_staff_plataforma' => 'boolean',
            'activo' => 'boolean',
            'dos_pasos_activo_at' => 'datetime',
            'dos_pasos_respaldo' => 'array',
            'password' => 'hashed',
        ];
    }

    public function nombreCompleto(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }
}
