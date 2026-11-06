<?php

namespace App\Modulos\Compartido\Correo;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía un correo de la bitácora. Es idempotente: si la fila ya no está «en_cola» (enviada,
 * suprimida o fallida), no hace nada. Vuelve a revisar la supresión justo antes de enviar.
 */
class EnviarCorreo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Reintentos: 1, 5, 15 y 60 minutos. */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(public int $bitacoraId, public Mailable $correo, public string $destinatario)
    {
        $this->onQueue('correo');
    }

    public function handle(): void
    {
        $db = DB::connection(CorreoTransaccional::CONEXION);
        $fila = $db->table('correos_bitacora')->where('id', $this->bitacoraId)->first();
        if (! $fila || $fila->estado !== 'en_cola') {
            return;
        }

        if (CorreoTransaccional::estaSuprimido($this->destinatario)) {
            $db->table('correos_bitacora')->where('id', $this->bitacoraId)->update(['estado' => 'suprimido']);

            return;
        }

        $db->table('correos_bitacora')->where('id', $this->bitacoraId)->increment('intentos');
        $enviado = Mail::to($this->destinatario)->send($this->correo);

        $db->table('correos_bitacora')->where('id', $this->bitacoraId)->update([
            'estado' => 'enviado',
            'mensaje_id' => $enviado?->getMessageId(),
            'enviado_at' => now(),
            'error' => null,
        ]);
    }

    public function failed(?Throwable $error): void
    {
        DB::connection(CorreoTransaccional::CONEXION)->table('correos_bitacora')->where('id', $this->bitacoraId)->update([
            'estado' => 'fallido',
            'error' => mb_substr((string) $error?->getMessage(), 0, 2000),
        ]);
    }
}
