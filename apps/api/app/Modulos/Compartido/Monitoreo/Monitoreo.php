<?php

namespace App\Modulos\Compartido\Monitoreo;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * EDUN-206 · Monitoreo de la API:
 * - salud(): base de datos, Redis y cola, sin datos sensibles (ni versiones ni mensajes de error).
 * - contarError5xx(): cuenta las respuestas 5xx por minuto (middleware ContarErrores).
 * - revisar(): lo corre «php artisan monitoreo:revisar» cada 5 minutos (timer del VPS) y avisa al
 *   equipo técnico si la salud falla, hay 5xx o la cola está atascada.
 * El aviso va a ALERTAS_WEBHOOK_URL (Slack, Teams, Discord o similar: POST JSON {text}) y, si
 * hay proveedor de correo, a ALERTAS_EMAIL. Un mismo aviso no se repite antes de 30 minutos.
 */
class Monitoreo
{
    public const UMBRAL_5XX = 5;

    public const UMBRAL_COLA = 200;

    public const MINUTOS_SILENCIO = 30;

    public function salud(): array
    {
        $servicios = [
            'base_de_datos' => $this->probar(fn () => DB::select('select 1 as ok')[0]->ok === 1),
            'redis' => $this->probar(fn () => (bool) Redis::connection()->ping()),
            'cola' => $this->probar(fn () => Queue::size() < self::UMBRAL_COLA),
        ];

        return ['estado' => collect($servicios)->every(fn ($s) => $s === 'ok') ? 'ok' : 'degradado', 'servicios' => $servicios];
    }

    public static function contarError5xx(): void
    {
        try {
            $clave = 'edunova:5xx:'.now()->format('YmdHi');
            Redis::connection()->incr($clave);
            Redis::connection()->expire($clave, 3600);
        } catch (Throwable) {
            // Sin Redis no se cuenta: la salud ya lo reporta.
        }
    }

    /** Errores 5xx de los últimos $minutos. */
    public function errores5xx(int $minutos = 5): int
    {
        try {
            $claves = collect(range(0, $minutos - 1))->map(fn ($i) => 'edunova:5xx:'.now()->subMinutes($i)->format('YmdHi'))->all();

            return (int) collect(Redis::connection()->mget($claves))->sum();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return list<string> problemas encontrados (vacío = todo bien) */
    public function revisar(): array
    {
        $problemas = [];
        $salud = $this->salud();
        foreach ($salud['servicios'] as $servicio => $estado) {
            if ($estado !== 'ok') {
                $problemas[] = $servicio === 'cola' ? 'La cola tiene '.self::tamanoCola().' trabajos pendientes (posible atasco del worker).' : "Falla el servicio «{$servicio}».";
            }
        }
        $errores = $this->errores5xx();
        if ($errores >= self::UMBRAL_5XX) {
            $problemas[] = "{$errores} respuestas 5xx en los últimos 5 minutos.";
        }
        $fallidos = $this->trabajosFallidos();
        if ($fallidos > 0) {
            $problemas[] = "{$fallidos} trabajos de la cola fallaron en la última hora.";
        }

        if ($problemas !== []) {
            $this->alertar('Edunova API: '.implode(' ', $problemas));
        }

        return $problemas;
    }

    /** Envía el aviso (una vez cada 30 minutos por texto). */
    public function alertar(string $texto): bool
    {
        $clave = 'edunova:alerta:'.md5($texto);
        if (! Cache::add($clave, true, now()->addMinutes(self::MINUTOS_SILENCIO))) {
            return false;
        }
        Log::warning('Alerta de monitoreo', ['texto' => $texto]);
        $url = config('services.alertas.webhook');
        if ($url) {
            try {
                Http::timeout(5)->post($url, ['text' => $texto, 'content' => $texto])->throw();
            } catch (Throwable $e) {
                Log::error('No se pudo enviar la alerta al webhook.', ['error' => class_basename($e)]);
            }
        }

        return true;
    }

    private function trabajosFallidos(): int
    {
        try {
            return DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function tamanoCola(): int
    {
        try {
            return Queue::size();
        } catch (Throwable) {
            return -1;
        }
    }

    private function probar(callable $prueba): string
    {
        try {
            return $prueba() ? 'ok' : 'falla';
        } catch (Throwable) {
            return 'falla';
        }
    }
}
