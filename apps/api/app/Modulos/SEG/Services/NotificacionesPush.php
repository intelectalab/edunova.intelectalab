<?php

namespace App\Modulos\SEG\Services;

use App\Modulos\Compartido\ContextoInstitucion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-196 · MOB-H02 Notificaciones push de la app.
 *
 * - Registro del token del dispositivo (Expo Notifications, que entrega por APNs y FCM).
 * - Cada aviso lleva `pantalla` (y su id) para que la app abra la pantalla relacionada.
 * - Preferencias por tipo: el usuario apaga los tipos que no quiere recibir por push.
 * - Los tokens que el servicio declara inválidos se borran.
 */
class NotificacionesPush
{
    public const TIPOS = [
        'pagos' => 'Pagos y vencimientos', 'notas' => 'Notas y libretas', 'asistencia' => 'Asistencia',
        'comunicados' => 'Comunicados', 'tareas' => 'Tareas', 'movilidad' => 'Movilidad', 'soporte' => 'Soporte',
    ];

    public function registrar(int $usuarioId, string $token, string $plataforma, ?string $version): void
    {
        if (! preg_match('/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_-]+\]$/', $token)) {
            throw ValidationException::withMessages(['token' => ['El token del dispositivo no es válido.']]);
        }
        // Un token es de un solo dispositivo: si otra persona ingresó antes en ese teléfono, pasa a quien ingresa ahora.
        DB::table('dispositivos_moviles')->upsert(
            [['user_id' => $usuarioId, 'token_push' => $token, 'plataforma' => $plataforma, 'app_version' => $version, 'ultimo_uso_at' => now()]],
            ['token_push'], ['user_id', 'plataforma', 'app_version', 'ultimo_uso_at'],
        );
    }

    public function quitar(int $usuarioId, string $token): void
    {
        DB::table('dispositivos_moviles')->where('user_id', $usuarioId)->where('token_push', $token)->delete();
    }

    /** @return list<array{tipo: string, nombre: string, push: bool}> */
    public function preferencias(int $usuarioId): array
    {
        $guardadas = DB::table('preferencias_notificacion')->where('user_id', $usuarioId)->pluck('push', 'tipo');

        return collect(self::TIPOS)->map(fn ($nombre, $tipo) => ['tipo' => $tipo, 'nombre' => $nombre, 'push' => (bool) ($guardadas[$tipo] ?? true)])->values()->all();
    }

    /** @param array<string, bool> $cambios */
    public function guardarPreferencias(int $usuarioId, array $cambios): array
    {
        $filas = collect($cambios)->only(array_keys(self::TIPOS))->map(fn ($v, $tipo) => ['user_id' => $usuarioId, 'tipo' => $tipo, 'push' => (bool) $v])->values()->all();
        if ($filas) {
            DB::table('preferencias_notificacion')->upsert($filas, ['user_id', 'tipo'], ['push']);
        }

        return $this->preferencias($usuarioId);
    }

    /**
     * Envía un aviso a usuarios del colegio activo y lo deja en `notificaciones`.
     *
     * @param  list<int>  $usuarios
     * @param  array{pantalla: string, id?: int|string}&array<string, mixed>  $datos
     * @return array{enviados: int, omitidos: int}
     */
    public function enviar(array $usuarios, string $tipo, string $titulo, string $cuerpo, array $datos): array
    {
        $apagados = DB::table('preferencias_notificacion')->whereIn('user_id', $usuarios)->where('tipo', $tipo)->where('push', false)->pluck('user_id')->all();
        $destino = array_values(array_diff(array_unique($usuarios), $apagados));
        $dispositivos = DB::table('dispositivos_moviles')->whereIn('user_id', $destino)->get(['user_id', 'token_push']);
        $inst = ContextoInstitucion::requerido();
        $mensajes = $dispositivos->map(fn ($d) => [
            'to' => $d->token_push, 'title' => $titulo, 'body' => $cuerpo, 'sound' => 'default', 'channelId' => $tipo,
            'data' => $datos + ['tipo' => $tipo],
        ])->values();

        $porToken = [];
        foreach ($mensajes->chunk(100) as $lote) {
            try {
                $r = Http::acceptJson()->timeout(10)->post((string) config('services.expo.push_url'), $lote->values()->all());
                foreach ($r->json('data', []) as $i => $ticket) {
                    $porToken[$lote->values()[$i]['to']] = $ticket;
                }
            } catch (\Throwable $e) {
                Log::warning('Push no enviado: '.$e->getMessage());
            }
        }
        $invalidos = collect($porToken)->filter(fn ($t) => ($t['details']['error'] ?? null) === 'DeviceNotRegistered')->keys();
        if ($invalidos->isNotEmpty()) {
            DB::table('dispositivos_moviles')->whereIn('token_push', $invalidos)->delete();
        }

        $ahora = now();
        $enviados = 0;
        foreach ($destino as $u) {
            $tokens = $dispositivos->where('user_id', $u)->pluck('token_push');
            $ok = $tokens->contains(fn ($t) => ($porToken[$t]['status'] ?? null) === 'ok');
            $enviados += $ok ? 1 : 0;
            DB::table('notificaciones')->insert([
                'institucion_id' => $inst, 'user_id' => $u, 'canal' => 'push', 'plantilla' => $tipo,
                'datos' => json_encode(['titulo' => $titulo, 'cuerpo' => $cuerpo] + $datos), 'estado' => $ok ? 'enviado' : 'fallido',
                'enviado_at' => $ok ? $ahora : null,
            ]);
        }

        return ['enviados' => $enviados, 'omitidos' => count($usuarios) - count($destino)];
    }
}
