<?php

namespace App\Modulos\SIA\Services;

use Illuminate\Support\Str;

/** Utilidades para comparar textos de los Excel del SIAGIE. */
final class TextoSiagie
{
    /** "Código del  Estudiante:" => "CODIGO DEL ESTUDIANTE" */
    public static function normalizar(mixed $texto): string
    {
        $texto = Str::ascii((string) $texto);
        $texto = mb_strtoupper($texto);
        $texto = preg_replace('/[^A-Z0-9 ]+/', ' ', $texto);

        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    /** Deja solo dígitos (para DNI y código de estudiante). */
    public static function soloDigitos(mixed $valor): string
    {
        return preg_replace('/\D+/', '', (string) $valor);
    }

    /** El código SIAGIE tiene 14 dígitos; Excel a veces quita ceros a la izquierda. */
    public static function codigoEstudiante(mixed $valor): ?string
    {
        $digitos = self::soloDigitos($valor);

        if ($digitos === '') {
            return null;
        }

        return strlen($digitos) <= 14 ? str_pad($digitos, 14, '0', STR_PAD_LEFT) : null;
    }

    /** El DNI tiene 8 dígitos; Excel a veces quita ceros a la izquierda. */
    public static function dni(mixed $valor): ?string
    {
        $digitos = self::soloDigitos($valor);

        if ($digitos === '') {
            return null;
        }

        return strlen($digitos) <= 8 ? str_pad($digitos, 8, '0', STR_PAD_LEFT) : $digitos;
    }

    /** Coincidencia entre un encabezado y una lista de alias normalizados. */
    public static function coincide(mixed $encabezado, array $alias): bool
    {
        $norm = self::normalizar($encabezado);

        if ($norm === '') {
            return false;
        }

        foreach ($alias as $a) {
            if ($norm === $a) {
                return true;
            }
        }

        return false;
    }
}
