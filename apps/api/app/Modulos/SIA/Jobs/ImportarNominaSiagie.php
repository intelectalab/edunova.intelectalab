<?php

namespace App\Modulos\SIA\Jobs;

use App\Modulos\Compartido\ContextoInstitucion;
use App\Modulos\SIA\Services\NominaSiagie;
use App\Modulos\SIA\Services\PlantillasNotas;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** EDUN-106 · Importa la nómina en la cola (por bloques; cada fila en su propia transacción). */
class ImportarNominaSiagie implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $institucionId, public int $procesoId) {}

    public function handle(NominaSiagie $nomina): void
    {
        DB::transaction(function () use ($nomina) {
            ContextoInstitucion::fijar($this->institucionId);
            $nomina->procesar($this->procesoId);
        });
    }

    /** Para las pruebas y el lote: mismo patrón con las plantillas. */
    public static function lote(int $institucionId, int $procesoId): void
    {
        DB::transaction(function () use ($institucionId, $procesoId) {
            ContextoInstitucion::fijar($institucionId);
            app(PlantillasNotas::class)->procesarLote($procesoId);
        });
    }
}
