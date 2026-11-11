<?php

namespace App\Modulos\PAG\Pasarela;

/**
 * Pasarela simulada (desarrollo, pruebas y servidor de pruebas): la familia ve una página de
 * pago de prueba y el aviso llega firmado con HMAC-SHA256, por el mismo camino que uno real.
 * Comisión simulada: 3,44 % + S/ 0,50 (solo para practicar la conciliación).
 */
class PasarelaSimulada implements PasarelaPagos
{
    public const CABECERA = 'x-pasarela-firma';

    public function probar(array $credenciales, string $ambiente): bool
    {
        return str_starts_with((string) ($credenciales['llave_secreta'] ?? ''), $ambiente === 'produccion' ? 'sk_live_' : 'sk_test_');
    }

    public function crearOrden(array $credenciales, string $ambiente, array $orden): array
    {
        return ['url' => rtrim((string) config('edunova.web_url'), '/').'/#/pago-simulado/'.rawurlencode($orden['orden']), 'referencia' => 'sim_'.$orden['orden']];
    }

    public function firmaValida(string $cuerpo, array $cabeceras, string $secreto): bool
    {
        $firma = (string) ($cabeceras[self::CABECERA] ?? '');

        return $firma !== '' && $secreto !== '' && hash_equals(self::firmar($cuerpo, $secreto), $firma);
    }

    public function evento(array $payload): array
    {
        $monto = round((float) ($payload['monto'] ?? 0), 2);

        return [
            'orden' => (string) ($payload['orden'] ?? ''), 'estado' => ($payload['estado'] ?? '') === 'pagada' ? 'pagada' : 'fallida',
            'medio' => $payload['medio'] ?? null, 'monto' => $monto, 'comision' => round($monto * 0.0344 + 0.5, 2),
            'fecha_abono' => now()->addWeekdays(2)->toDateString(),
        ];
    }

    public static function firmar(string $cuerpo, string $secreto): string
    {
        return hash_hmac('sha256', $cuerpo, $secreto);
    }
}
