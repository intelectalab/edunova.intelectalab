<?php

namespace App\Modulos\SIA\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** EDUN-111 · Genera en la cola las plantillas de todas las secciones y arma el ZIP. */
class GenerarPlantillasLote implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $institucionId, public int $procesoId) {}

    public function handle(): void
    {
        ImportarNominaSiagie::lote($this->institucionId, $this->procesoId);
    }
}
