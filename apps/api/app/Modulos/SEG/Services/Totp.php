<?php

namespace App\Modulos\SEG\Services;

/**
 * EDUN-38 · Códigos de un solo uso basados en tiempo (RFC 6238, compatibles con Google
 * Authenticator, Microsoft Authenticator, Authy…): 6 dígitos, pasos de 30 s, HMAC-SHA1.
 */
class Totp
{
    private const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const PASO = 30;

    /** Secreto aleatorio de 160 bits en base32. */
    public static function generarSecreto(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function codigo(string $secreto, ?int $momento = null): string
    {
        $contador = intdiv($momento ?? time(), self::PASO);
        $hash = hash_hmac('sha1', pack('J', $contador), self::desdeBase32($secreto), true);
        $desfase = ord($hash[19]) & 0x0F;
        $numero = ((ord($hash[$desfase]) & 0x7F) << 24) | (ord($hash[$desfase + 1]) << 16) | (ord($hash[$desfase + 2]) << 8) | ord($hash[$desfase + 3]);

        return str_pad((string) ($numero % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Acepta el paso actual y uno antes o después (relojes desfasados hasta 30 s).
     * Devuelve el contador usado, para no aceptar dos veces el mismo código.
     */
    public static function verificar(string $secreto, string $codigo, ?int $momento = null): ?int
    {
        $codigo = preg_replace('/\D/', '', $codigo) ?? '';
        if (strlen($codigo) !== 6) {
            return null;
        }
        $momento ??= time();
        foreach ([0, -1, 1] as $salto) {
            $t = $momento + $salto * self::PASO;
            if (hash_equals(self::codigo($secreto, $t), $codigo)) {
                return intdiv($t, self::PASO);
            }
        }

        return null;
    }

    public static function uri(string $secreto, string $cuenta, string $emisor = 'Edunova'): string
    {
        return 'otpauth://totp/'.rawurlencode($emisor.':'.$cuenta).'?'.http_build_query([
            'secret' => $secreto, 'issuer' => $emisor, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => self::PASO,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $salida = '';
        foreach (str_split($bits, 5) as $grupo) {
            $salida .= self::ALFABETO[bindec(str_pad($grupo, 5, '0'))];
        }

        return $salida;
    }

    private static function desdeBase32(string $texto): string
    {
        $texto = strtoupper(preg_replace('/[\s=]/', '', $texto) ?? '');
        $bits = '';
        foreach (str_split($texto) as $c) {
            $pos = strpos(self::ALFABETO, $c);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }
        $salida = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $salida .= chr(bindec($byte));
            }
        }

        return $salida;
    }
}
