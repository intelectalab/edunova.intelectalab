<?php

namespace App\Modulos\PLA\Jobs;

use App\Modulos\PLA\Services\ExportacionColegio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** EDUN-34 · Arma la exportación completa del colegio en la cola (puede tardar). */
class GenerarExportacion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public int $exportacionId) {}

    public function handle(ExportacionColegio $exportacion): void
    {
        $exportacion->generar($this->exportacionId);
    }
}
