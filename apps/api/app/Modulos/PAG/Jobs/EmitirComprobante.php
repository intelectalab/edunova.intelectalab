<?php

namespace App\Modulos\PAG\Jobs;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\PAG\Services\Comprobantes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EDUN-77 · Envía el comprobante al proveedor. Si falla, vuelve a la cola con espera creciente
 * (1, 5, 15, 30 y 60 minutos); agotados los intentos queda «pendiente» para reenviarlo a mano.
 * No relanza la excepción: el cobro en caja nunca falla porque el proveedor no responda.
 */
class EmitirComprobante implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public int $timeout = 120;

    public function __construct(public int $institucionId, public int $comprobanteId) {}

    public function handle(Comprobantes $comprobantes): void
    {
        $estado = DB::transaction(function () use ($comprobantes) {
            ContextoInstitucion::fijar($this->institucionId);

            return $comprobantes->enviar($this->comprobanteId);
        });
        if ($estado !== 'pendiente') {
            return;
        }
        $intento = $this->attempts();
        if ($this->job && $intento < $this->tries) {
            $this->release(Comprobantes::ESPERAS[$intento - 1] ?? 3600);

            return;
        }
        Log::error("Comprobante {$this->comprobanteId} del colegio {$this->institucionId} sin emitir tras {$intento} intentos: queda pendiente para reenviarlo.");
    }
}
