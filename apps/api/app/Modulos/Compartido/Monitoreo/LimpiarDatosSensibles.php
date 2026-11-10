<?php

namespace App\Modulos\Compartido\Monitoreo;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * EDUN-206 · Los registros no guardan contraseñas, tokens, datos de salud ni documentos de
 * menores: este «tap» de Monolog reemplaza esos valores por «[oculto]» en el contexto y en el
 * mensaje antes de escribir. Se aplica a los canales single y daily (config/logging.php).
 */
class LimpiarDatosSensibles
{
    /** Claves cuyo valor nunca se escribe (se compara sin mayúsculas). */
    private const CLAVES = '/(pass(word)?|clave|contrase|secret|token|authorization|cookie|api[_-]?key|numero_doc|documento|dni|tipo_doc|diagnostic|alergi|medic|salud|enfermedad|tarjeta|cvv)/i';

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->pushProcessor(fn (LogRecord $r) => $r->with(
                message: self::limpiarTexto($r->message),
                context: self::limpiar($r->context),
                extra: self::limpiar($r->extra),
            ));
        }
    }

    public static function limpiar(array $datos): array
    {
        foreach ($datos as $clave => $valor) {
            if (is_string($clave) && preg_match(self::CLAVES, $clave)) {
                $datos[$clave] = '[oculto]';
            } elseif (is_array($valor)) {
                $datos[$clave] = self::limpiar($valor);
            } elseif (is_string($valor)) {
                $datos[$clave] = self::limpiarTexto($valor);
            }
        }

        return $datos;
    }

    /** Tokens Bearer, tokens de Sanctum («id|clave») y DNI/RUC sueltos en un texto. */
    public static function limpiarTexto(string $texto): string
    {
        $texto = preg_replace('/(Bearer\s+)[A-Za-z0-9._~+\/|=-]+/i', '$1[oculto]', $texto) ?? $texto;
        $texto = preg_replace('/\b\d+\|[A-Za-z0-9]{30,}\b/', '[token oculto]', $texto) ?? $texto;
        $texto = preg_replace('/("?(password|clave|token|secret)"?\s*[:=]\s*)"?[^",\s}]+"?/i', '$1"[oculto]"', $texto) ?? $texto;

        return preg_replace('/\b(\d{8}|\d{11})\b/', '[doc oculto]', $texto) ?? $texto;
    }
}
