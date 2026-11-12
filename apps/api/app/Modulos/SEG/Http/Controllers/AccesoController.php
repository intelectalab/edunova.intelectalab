<?php

namespace App\Modulos\SEG\Http\Controllers;

use App\Models\User;
use App\Modulos\Compartido\Correo\CorreoTransaccional;
use App\Modulos\SEG\Correo\RecuperarClave;
use App\Modulos\SIT\Services\Integraciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Formas fáciles de entrar:
 *
 * - Recuperar la contraseña (POST /auth/recuperar y /auth/restablecer): con el DNI o el correo se
 *   envía un enlace de un solo uso que vence en 60 minutos. La respuesta es siempre la misma
 *   (no revela si el usuario existe). Sin correo, el colegio reenvía la invitación por WhatsApp.
 * - Acceso de demostración (GET|POST /auth/demo): SOLO si el servidor lo activa
 *   (EDUNOVA_DEMO_ACCESO=true, servidor de pruebas). Entra con un usuario del colegio ficticio
 *   según el rol elegido, por 8 horas, sin contraseña ni segundo factor.
 */
class AccesoController
{
    public const MINUTOS_ENLACE = 60;

    public const HORAS_DEMO = 8;

    /** Roles que se ofrecen en la demostración (si el colegio ficticio tiene un usuario con ese rol). */
    public const ROLES_DEMO = ['director', 'secretaria', 'tesoreria', 'docente', 'tutor', 'auxiliar', 'apoderado'];

    public function __construct(private CorreoTransaccional $correo) {}

    public function recuperar(Request $request): JsonResponse
    {
        $d = $request->validate(['usuario' => ['required', 'string', 'max:150']], ['usuario.required' => 'Escribe tu DNI o correo.']);
        $usuario = User::query()->where('email', mb_strtolower(trim($d['usuario'])))->orWhere('numero_documento', trim($d['usuario']))->first();
        if ($usuario && $usuario->email) {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(['email' => $usuario->email], ['token' => Hash::make($token), 'created_at' => now()]);
            $url = rtrim((string) config('edunova.web_url'), '/').'/#/restablecer?token='.$token.'&correo='.rawurlencode($usuario->email);
            try {
                $this->correo->enviar(new RecuperarClave(['nombre' => $usuario->nombres, 'url' => $url, 'minutos' => self::MINUTOS_ENLACE]), $usuario->email, 'recuperar_clave');
            } catch (\Throwable $e) {
                Log::warning('No se pudo enviar el correo para recuperar la contraseña.', ['error' => class_basename($e)]);
            }
        }

        return response()->json(['message' => 'Si tus datos están registrados y tienes correo, te enviamos un enlace para crear una nueva contraseña. Revisa también la carpeta de spam. Si no tienes correo, pide al colegio que te reenvíe la invitación por WhatsApp.']);
    }

    public function restablecer(Request $request): JsonResponse
    {
        $d = $request->validate([
            'correo' => ['required', 'email'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ], [
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 10 caracteres.',
            'password.letters' => 'La contraseña debe tener letras.',
            'password.numbers' => 'La contraseña debe tener al menos un número.',
        ]);
        $fila = DB::table('password_reset_tokens')->where('email', mb_strtolower($d['correo']))->first();
        $vigente = $fila && Hash::check($d['token'], $fila->token) && now()->subMinutes(self::MINUTOS_ENLACE)->lessThan($fila->created_at);
        if (! $vigente) {
            throw ValidationException::withMessages(['token' => ['El enlace venció o ya se usó. Pide uno nuevo.']]);
        }
        $usuario = User::query()->where('email', mb_strtolower($d['correo']))->firstOrFail();
        $usuario->forceFill(['password' => Hash::make($d['password'])])->save();
        DB::table('password_reset_tokens')->where('email', $usuario->email)->delete();
        // Cierra las sesiones abiertas: si alguien más tenía la contraseña, queda fuera.
        $usuario->tokens()->delete();
        Log::info('Contraseña restablecida por correo.', ['user_id' => $usuario->id, 'ip' => $request->ip()]);

        return response()->json(['message' => 'Listo: ya puedes ingresar con tu nueva contraseña.']);
    }

    public function opcionesDemo(): JsonResponse
    {
        $demo = Integraciones::demo();
        if (! $demo['activo']) {
            return response()->json(['activo' => false, 'roles' => []]);
        }
        $inst = DB::connection('propietario')->table('instituciones')->where('slug', $demo['colegio'])->first(['id', 'nombre']);
        $roles = $inst ? DB::connection('propietario')->table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')
            ->where('m.institucion_id', $inst->id)->whereIn('r.codigo', self::ROLES_DEMO)->distinct()->get(['r.codigo', 'r.nombre'])
            ->sortBy(fn ($r) => array_search($r->codigo, self::ROLES_DEMO, true))->values() : collect();

        return response()->json(['activo' => $inst !== null && $roles->isNotEmpty(), 'colegio' => $inst?->nombre, 'roles' => $roles]);
    }

    public function entrarDemo(Request $request): JsonResponse
    {
        $demo = Integraciones::demo();
        abort_unless($demo['activo'], 404);
        $d = $request->validate(['rol' => ['required', Rule::in(self::ROLES_DEMO)]]);
        $db = DB::connection('propietario');
        $inst = (int) $db->table('instituciones')->where('slug', $demo['colegio'])->value('id');
        $userId = $db->table('membresias as m')->join('roles as r', 'r.id', '=', 'm.rol_id')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.institucion_id', $inst)->where('r.codigo', $d['rol'])->orderBy('u.id')->value('u.id');
        abort_if(! $userId, 404, 'No hay un usuario de demostración con ese rol.');
        $usuario = User::query()->findOrFail($userId);
        $nuevo = $usuario->createToken('demo', ['*'], now()->addHours(self::HORAS_DEMO));
        $nuevo->accessToken->forceFill(['institucion_id' => $inst, 'dos_pasos_ok' => true])->save();
        Log::info('Acceso de demostración', ['rol' => $d['rol'], 'ip' => $request->ip()]);

        return app(SesionController::class)->conToken($usuario, $nuevo->plainTextToken, PersonalAccessToken::findToken($nuevo->plainTextToken));
    }
}
