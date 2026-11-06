<?php

namespace App\Modulos\Compartido\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-208 · Único punto de salida del correo transaccional (invitaciones, comprobantes,
 * alertas, confirmación de reclamos…).
 *
 * Cada correo queda en correos_bitacora y sale por la cola (EnviarCorreo). Si la dirección
 * está en correos_suprimidos (rebote o queja), no se envía y queda como «suprimido».
 * Usa la conexión de plataforma: la bitácora y la supresión no dependen del colegio activo.
 */
class CorreoTransaccional
{
    public const CONEXION = 'plataforma';

    /** @return int id de la fila en correos_bitacora */
    public function enviar(Mailable $correo, string $destinatario, string $plantilla, ?int $institucionId = null): int
    {
        $destinatario = self::normalizar($destinatario);
        $suprimido = self::estaSuprimido($destinatario);

        $id = DB::connection(self::CONEXION)->table('correos_bitacora')->insertGetId([
            'institucion_id' => $institucionId,
            'destinatario' => $destinatario,
            'plantilla' => $plantilla,
            'asunto' => mb_substr(self::asunto($correo) ?? $plantilla, 0, 200),
            'estado' => $suprimido ? 'suprimido' : 'en_cola',
        ]);

        if (! $suprimido) {
            EnviarCorreo::dispatch($id, $correo, $destinatario);
        }

        return $id;
    }

    /** Asunto del correo, tanto si se define con envelope() como con build(). */
    private static function asunto(Mailable $correo): ?string
    {
        if (method_exists($correo, 'envelope')) {
            return $correo->envelope()->subject;
        }
        if ($correo->subject === null && method_exists($correo, 'build')) {
            // Se arma una copia para no alterar el correo que irá a la cola.
            $copia = clone $correo;
            app()->call([$copia, 'build']);

            return $copia->subject;
        }

        return $correo->subject;
    }

    public static function normalizar(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function estaSuprimido(string $email): bool
    {
        return DB::connection(self::CONEXION)->table('correos_suprimidos')->where('email', self::normalizar($email))->exists();
    }

    /** Registra un rebote duro o una queja: detiene los envíos futuros a esa dirección. */
    public static function suprimir(string $email, string $motivo, ?string $detalle = null): void
    {
        $email = self::normalizar($email);
        $db = DB::connection(self::CONEXION);
        $db->table('correos_suprimidos')->upsert(
            [['email' => $email, 'motivo' => $motivo, 'detalle' => $detalle]],
            ['email'],
            ['motivo', 'detalle'],
        );
        // El último envío a esa dirección queda marcado con el evento recibido.
        $ultimo = $db->table('correos_bitacora')->where('destinatario', $email)->where('estado', 'enviado')->max('id');
        if ($ultimo && in_array($motivo, ['rebote', 'queja'], true)) {
            $db->table('correos_bitacora')->where('id', $ultimo)->update(['estado' => $motivo === 'rebote' ? 'rebotado' : 'queja']);
        }
    }
}
