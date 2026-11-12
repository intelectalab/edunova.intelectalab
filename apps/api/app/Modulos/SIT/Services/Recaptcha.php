<?php

namespace App\Modulos\SIT\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * EDUN-294 · reCAPTCHA v3 del Libro de Reclamaciones. Solo se exige cuando hay clave secreta
 * (RECAPTCHA_SECRETO); sin ella valen el campo trampa y los límites de uso por IP.
 * Si Google no responde, el reclamo no se pierde: se acepta y queda en el log.
 */
class Recaptcha
{
    public function activo(): bool
    {
        return Integraciones::recaptcha()['secreto'] !== null;
    }

    public function valido(?string $token, string $accion, ?string $ip): bool
    {
        if (! $this->activo()) {
            return true;
        }
        if (! $token) {
            return false;
        }

        try {
            $r = Http::asForm()->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => Integraciones::recaptcha()['secreto'],
                'response' => $token,
                'remoteip' => $ip,
            ])->json();
        } catch (Throwable $e) {
            Log::warning('reCAPTCHA no respondió; se acepta el envío.', ['error' => $e->getMessage()]);

            return true;
        }

        return ($r['success'] ?? false) === true
            && ($r['action'] ?? $accion) === $accion
            && (float) ($r['score'] ?? 0) >= Integraciones::recaptcha()['umbral'];
    }
}
