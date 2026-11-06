<?php

namespace App\Modulos\SIT\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-233 / SIT-044 · Guardar borrador y publicar (TRASPASO §6, flujo editorial).
 *
 * - Guardar borrador: escribe cms.secciones.borrador; lo publicado (config) no cambia.
 * - Publicar: copia borrador → config, crea cms.versiones y registra «publicado» en
 *   cms.auditoria, todo en una transacción: o se publica todo lo pedido o nada.
 *   Al confirmar, pide al sitio que revalide la landing (si falla, el ISR de 5 min la renueva).
 * - Toda escritura queda en cms.auditoria en la misma transacción.
 *
 * Corre dentro de la transacción que abre el middleware «cms» (app.rol_cms = superadmin).
 */
class EditorCms
{
    public function leer(string $clave): object
    {
        return DB::table('cms.secciones')->where('clave', $clave)->first(['clave', 'nombre', 'config', 'borrador', 'updated_at'])
            ?? abort(404, 'No encontramos esa sección.');
    }

    public function guardarBorrador(string $clave, array $datos, User $usuario, ?string $ip): object
    {
        $reglas = ReglasSecciones::para($clave) ?? abort(404, 'Esa sección no se edita desde aquí.');
        $contenido = validator($datos, $reglas, ReglasSecciones::mensajes())->validate();
        $actual = $this->leer($clave);

        DB::table('cms.secciones')->where('clave', $clave)->update([
            'borrador' => json_encode($contenido, JSON_UNESCAPED_UNICODE),
            'updated_by' => $usuario->id,
        ]);
        $this->auditar($usuario, $clave, 'borrador', "{$actual->nombre} · borrador guardado", $actual->borrador ?? $actual->config, $contenido, $ip);

        return $this->leer($clave);
    }

    /** @param  list<string>  $claves */
    public function publicar(array $claves, User $usuario, ?string $ip): array
    {
        $publicadas = [];
        // Savepoint: si una sección falla, no queda publicada ninguna.
        DB::transaction(function () use ($claves, $usuario, $ip, &$publicadas) {
            foreach (array_unique($claves) as $clave) {
                $fila = DB::table('cms.secciones')->where('clave', $clave)->lockForUpdate()->first();
                if (! $fila) {
                    throw ValidationException::withMessages(['claves' => ["La sección «{$clave}» no existe."]]);
                }
                if ($fila->borrador === null) {
                    continue;
                }
                DB::table('cms.secciones')->where('clave', $clave)->update([
                    'config' => $fila->borrador,
                    'borrador' => null,
                    'updated_by' => $usuario->id,
                ]);
                DB::table('cms.versiones')->insert([
                    'seccion' => $clave,
                    'entidad' => 'secciones',
                    'entidad_id' => (string) $fila->id,
                    'contenido' => $fila->borrador,
                    'publicado_por' => $usuario->id,
                ]);
                $this->auditar($usuario, $clave, 'publicado', "{$fila->nombre} · publicado", $fila->config, json_decode($fila->borrador, true), $ip);
                $publicadas[] = $clave;
            }
        });

        if ($publicadas !== []) {
            DB::afterCommit(fn () => $this->revalidarSitio());
        }

        return $publicadas;
    }

    /** Franja de estado: última publicación y si hay cambios sin publicar. */
    public function estado(): array
    {
        $ultima = DB::table('cms.versiones as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.publicado_por')
            ->orderByDesc('v.publicado_en')
            ->first(['v.publicado_en', DB::raw("trim(u.nombres || ' ' || u.apellidos) as por")]);

        return [
            'ultima_publicacion' => $ultima ? ['en' => $ultima->publicado_en, 'por' => $ultima->por] : null,
            'pendientes' => DB::table('cms.secciones')->whereNotNull('borrador')->pluck('clave')->all(),
        ];
    }

    private function auditar(User $usuario, string $clave, string $accion, string $detalle, mixed $antes, mixed $despues, ?string $ip): void
    {
        DB::table('cms.auditoria')->insert([
            'usuario_id' => $usuario->id,
            'usuario_rol' => 'superadmin',
            'seccion' => $clave,
            'accion' => $accion,
            'entidad' => 'secciones',
            'entidad_id' => $clave,
            'detalle' => mb_substr($detalle, 0, 250),
            'valor_antes' => is_string($antes) ? $antes : json_encode($antes, JSON_UNESCAPED_UNICODE),
            'valor_despues' => is_string($despues) ? $despues : json_encode($despues, JSON_UNESCAPED_UNICODE),
            'ip' => $ip,
        ]);
    }

    /** Revalida la landing en el sitio Next.js. No bloquea la publicación si el sitio no responde. */
    private function revalidarSitio(): void
    {
        $url = config('services.sitio.revalidar_url');
        $secreto = config('services.sitio.revalidar_secreto');
        if (! $url || ! $secreto) {
            return;
        }
        try {
            Http::timeout(3)->withHeaders(['X-Edunova-Revalidar' => $secreto])->post($url, ['etiquetas' => ['landing']])->throw();
        } catch (\Throwable $e) {
            Log::warning('No se pudo revalidar el sitio; se actualizará con el ISR de 5 minutos.', ['error' => $e->getMessage()]);
        }
    }
}
