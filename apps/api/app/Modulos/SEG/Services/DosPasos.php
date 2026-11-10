<?php

namespace App\Modulos\SEG\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * EDUN-38 / SEG-H04 · Segundo factor con app autenticadora y códigos de respaldo.
 *
 * - Obligatorio para promotor, director y tesorería (en cualquier colegio) y para el staff de
 *   la plataforma (docs/05: la consola /plataforma solo con 2FA). Quien lo activa por su cuenta
 *   también lo usa siempre.
 * - El secreto se guarda cifrado con la llave de la aplicación; los códigos de respaldo, con hash.
 * - La sesión (token) queda verificada con personal_access_tokens.dos_pasos_ok.
 */
class DosPasos
{
    public const ROLES_OBLIGATORIOS = ['promotor', 'director', 'tesoreria'];

    public const INTENTOS = 5;

    public const CODIGOS_RESPALDO = 10;

    public function __construct(private Sesion $sesion) {}

    public function activo(User $usuario): bool
    {
        return $usuario->dos_pasos_activo_at !== null;
    }

    /** ¿Debe usar segundo factor para entrar? */
    public function requerido(User $usuario): bool
    {
        if ($this->activo($usuario) || $this->sesion->rolesPlataforma($usuario) !== []) {
            return true;
        }
        foreach ($this->sesion->colegios($usuario) as $colegio) {
            if (array_intersect(self::ROLES_OBLIGATORIOS, $colegio['roles']) !== []) {
                return true;
            }
        }

        return false;
    }

    /** ok | no_requerido | configurar | verificar */
    public function estado(User $usuario, mixed $token): string
    {
        if (! $this->requerido($usuario)) {
            return 'no_requerido';
        }
        if (self::verificado($token)) {
            return 'ok';
        }

        return $this->activo($usuario) ? 'verificar' : 'configurar';
    }

    public static function verificado(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken && (bool) $token->getAttribute('dos_pasos_ok');
    }

    /**
     * Paso 1 de la activación: secreto nuevo (aún sin activar) y su QR.
     *
     * @return array{secreto: string, uri: string, qr: string}
     */
    public function iniciar(User $usuario): array
    {
        if ($this->activo($usuario)) {
            throw ValidationException::withMessages(['codigo' => ['El segundo factor ya está activo.']]);
        }
        $secreto = Totp::generarSecreto();
        $usuario->forceFill(['dos_pasos_secreto' => Crypt::encryptString($secreto)])->save();
        $uri = Totp::uri($secreto, $usuario->email ?? $usuario->numero_documento ?? (string) $usuario->id);

        return ['secreto' => trim(chunk_split($secreto, 4, ' ')), 'uri' => $uri, 'qr' => self::qr($uri)];
    }

    /**
     * Paso 2: con el primer código correcto queda activo y se entregan los códigos de respaldo
     * (se muestran una sola vez). La sesión actual queda verificada.
     *
     * @return list<string>
     */
    public function activar(User $usuario, string $codigo, mixed $token): array
    {
        if ($this->activo($usuario) || ! $usuario->dos_pasos_secreto) {
            throw ValidationException::withMessages(['codigo' => ['Primero genera el código QR.']]);
        }
        $this->limitar($usuario);
        if (! $this->codigoTotpValido($usuario, $codigo)) {
            $this->fallo($usuario);
        }
        $codigos = $this->nuevosRespaldos($usuario);
        $usuario->forceFill(['dos_pasos_activo_at' => now()])->save();
        $this->marcar($token);

        return $codigos;
    }

    /** Al ingresar: código de la app o uno de respaldo (que se gasta). */
    public function verificar(User $usuario, string $codigo, mixed $token): bool
    {
        if (! $this->activo($usuario)) {
            throw ValidationException::withMessages(['codigo' => ['Activa primero el segundo factor.']]);
        }
        $this->limitar($usuario);
        $usoRespaldo = false;
        if (! $this->codigoTotpValido($usuario, $codigo)) {
            if (! $this->gastarRespaldo($usuario, $codigo)) {
                $this->fallo($usuario);
            }
            $usoRespaldo = true;
        }
        RateLimiter::clear($this->clave($usuario));
        $this->marcar($token);

        return $usoRespaldo;
    }

    /** @return list<string> códigos nuevos; los anteriores dejan de servir */
    public function regenerarRespaldos(User $usuario, string $codigo): array
    {
        $this->limitar($usuario);
        if (! $this->activo($usuario) || ! $this->codigoTotpValido($usuario, $codigo)) {
            $this->fallo($usuario);
        }

        return $this->nuevosRespaldos($usuario);
    }

    public function respaldosRestantes(User $usuario): int
    {
        return count($usuario->dos_pasos_respaldo ?? []);
    }

    private function codigoTotpValido(User $usuario, string $codigo): bool
    {
        $secreto = Crypt::decryptString((string) $usuario->dos_pasos_secreto);
        $contador = Totp::verificar($secreto, $codigo);
        // Un mismo código no se acepta dos veces (otra persona mirando la pantalla).
        if ($contador === null || ! Cache::add("totp:{$usuario->id}:{$contador}", true, Totp::PASO * 3)) {
            return false;
        }

        return true;
    }

    private function gastarRespaldo(User $usuario, string $codigo): bool
    {
        $codigo = strtolower(preg_replace('/[^a-z0-9]/i', '', $codigo) ?? '');
        $lista = $usuario->dos_pasos_respaldo ?? [];
        foreach ($lista as $i => $hash) {
            if (Hash::check($codigo, $hash)) {
                unset($lista[$i]);
                $usuario->forceFill(['dos_pasos_respaldo' => array_values($lista)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function nuevosRespaldos(User $usuario): array
    {
        $codigos = [];
        for ($i = 0; $i < self::CODIGOS_RESPALDO; $i++) {
            $codigos[] = strtolower(Str::random(5).'-'.Str::random(5));
        }
        $usuario->forceFill(['dos_pasos_respaldo' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codigos)])->save();

        return $codigos;
    }

    private function marcar(mixed $token): void
    {
        if ($token instanceof PersonalAccessToken) {
            $token->forceFill(['dos_pasos_ok' => true])->save();
        }
    }

    private function clave(User $usuario): string
    {
        return 'dos-pasos:'.$usuario->id;
    }

    private function limitar(User $usuario): void
    {
        if (RateLimiter::tooManyAttempts($this->clave($usuario), self::INTENTOS)) {
            $minutos = (int) ceil(RateLimiter::availableIn($this->clave($usuario)) / 60);
            throw ValidationException::withMessages(['codigo' => ["Demasiados intentos. Vuelve a intentarlo en {$minutos} min."]])->status(429);
        }
    }

    private function fallo(User $usuario): never
    {
        RateLimiter::hit($this->clave($usuario), 15 * 60);
        throw ValidationException::withMessages(['codigo' => ['El código no es correcto o ya venció.']]);
    }

    private static function qr(string $texto): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($texto);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
