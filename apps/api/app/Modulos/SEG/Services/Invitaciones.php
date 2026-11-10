<?php

namespace App\Modulos\SEG\Services;

use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\SEG\Correo\InvitacionUsuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-36 / SEG-H02 · Invitaciones con enlace de un solo uso, por correo o por WhatsApp.
 *
 * Solo se guarda el hash del enlace. Por WhatsApp no hay proveedor contratado: se arma el
 * enlace wa.me con el mensaje listo para que quien invita lo envíe desde su teléfono.
 * Una invitación nueva anula las pendientes de la misma persona en el mismo colegio.
 */
class Invitaciones
{
    public const DIAS_VIGENCIA = 7;

    public const NOMBRE_ROL = [
        'promotor' => 'Promotor(a)', 'director' => 'Director(a)', 'subdirector' => 'Subdirector(a)', 'coordinador' => 'Coordinador(a) académico',
        'secretaria' => 'Secretaría', 'tesoreria' => 'Tesorería', 'docente' => 'Docente', 'tutor' => 'Tutor(a)', 'auxiliar' => 'Auxiliar',
        'psicologia' => 'Psicología', 'enfermeria' => 'Enfermería', 'bibliotecario' => 'Bibliotecario(a)', 'movilidad' => 'Movilidad',
        'comedor' => 'Comedor', 'contador' => 'Contador(a)', 'admision' => 'Admisión', 'porteria' => 'Portería', 'conductor' => 'Conductor(a)',
        'laboratorio' => 'Laboratorio', 'nutricionista' => 'Nutricionista', 'tienda' => 'Tienda escolar', 'apoderado' => 'Apoderado', 'alumno' => 'Alumno',
    ];

    public function __construct(private CorreoTransaccional $correo) {}

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('edunova.web_url'), '/').'/?invitacion='.$token;
    }

    /**
     * Crea la invitación y la envía por el canal elegido.
     *
     * @param  list<string>  $roles
     * @return array{id: int, canal: string, vence: string, whatsapp_url: string|null}
     */
    public function invitar(int $institucionId, object $usuario, array $roles, string $canal, ?int $invitadoPor, ?string $conexion = null): array
    {
        if ($canal === 'correo' && ! $usuario->email) {
            throw ValidationException::withMessages(['canal' => ['Esta persona no tiene correo: invítala por WhatsApp.']]);
        }
        if ($canal === 'whatsapp' && ! self::celularWhatsapp($usuario->celular ?? null)) {
            throw ValidationException::withMessages(['canal' => ['Esta persona no tiene un celular válido: invítala por correo.']]);
        }

        $db = DB::connection($conexion);
        $db->table('invitaciones')->where('institucion_id', $institucionId)->where('user_id', $usuario->id)
            ->whereNull('usada_at')->where('expira_at', '>', now())->update(['expira_at' => now()]);

        $token = Str::random(48);
        $expira = now()->addDays(self::DIAS_VIGENCIA);
        $id = (int) $db->table('invitaciones')->insertGetId([
            'institucion_id' => $institucionId, 'user_id' => $usuario->id, 'token_hash' => self::hash($token), 'canal' => $canal,
            'roles' => json_encode(array_values($roles)), 'invitado_por' => $invitadoPor, 'expira_at' => $expira,
        ]);

        $colegio = (string) $db->table('instituciones')->where('id', $institucionId)->value('nombre');
        $datos = [
            'nombre' => $usuario->nombres, 'colegio' => $colegio, 'url' => self::url($token),
            'roles' => implode(', ', array_map(fn ($r) => self::NOMBRE_ROL[$r] ?? $r, $roles)),
            'vence' => $expira->timezone('America/Lima')->translatedFormat('d/m/Y'),
        ];

        $whatsapp = null;
        if ($canal === 'correo') {
            $this->correo->enviar(new InvitacionUsuario($datos), $usuario->email, 'invitacion_usuario', $institucionId);
        } else {
            $texto = "Hola, {$datos['nombre']}. {$colegio} te invitó a Edunova como {$datos['roles']}. Crea tu contraseña aquí (sirve una sola vez, vence el {$datos['vence']}): {$datos['url']}";
            $whatsapp = 'https://wa.me/'.self::celularWhatsapp($usuario->celular).'?text='.rawurlencode($texto);
        }

        return ['id' => $id, 'canal' => $canal, 'vence' => $expira->toIso8601String(), 'whatsapp_url' => $whatsapp];
    }

    /** Celular en formato internacional sin «+» (Perú por defecto). */
    public static function celularWhatsapp(?string $celular): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $celular) ?? '';
        if (preg_match('/^9\d{8}$/', $digitos)) {
            return '51'.$digitos;
        }

        return preg_match('/^\d{10,15}$/', $digitos) ? $digitos : null;
    }

    /** @return array{colegio: string, nombres: string, apellidos: string, email: string|null, tiene_password: bool}|null */
    public function ver(string $token): ?array
    {
        $fila = DB::selectOne('select * from fn_invitacion_por_token(?)', [self::hash($token)]);
        if (! $fila || $fila->usada_at !== null || now()->greaterThan($fila->expira_at)) {
            return null;
        }

        return [
            'colegio' => $fila->colegio, 'nombres' => $fila->nombres, 'apellidos' => $fila->apellidos,
            'email' => $fila->email, 'tiene_password' => (bool) $fila->tiene_password,
        ];
    }

    public function aceptar(string $token, ?string $password): void
    {
        $invitacion = $this->ver($token);
        if (! $invitacion) {
            throw ValidationException::withMessages(['token' => ['El enlace ya se usó o venció. Pide una invitación nueva.']])->status(410);
        }
        if (! $invitacion['tiene_password'] && ! $password) {
            throw ValidationException::withMessages(['password' => ['Crea tu contraseña.']]);
        }
        $usuario = DB::selectOne('select fn_aceptar_invitacion(?, ?) as id', [self::hash($token), $password ? Hash::make($password) : null]);
        if (! $usuario?->id) {
            throw ValidationException::withMessages(['token' => ['El enlace ya se usó o venció. Pide una invitación nueva.']])->status(410);
        }
    }
}
