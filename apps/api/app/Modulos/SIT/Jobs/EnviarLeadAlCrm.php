<?php

namespace App\Modulos\SIT\Jobs;

use App\Modulos\SIT\Services\EnvioCrm;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** EDUN-275 · Envía un lead al CRM; la cola lo reintenta a los 1, 5, 15 y 60 minutos. */
class EnviarLeadAlCrm implements ShouldQueue
{
    use Queueable;

    public int $tries = EnvioCrm::MAX_INTENTOS;

    public function __construct(public int $leadId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return EnvioCrm::ESPERAS;
    }

    public function handle(EnvioCrm $crm): void
    {
        $crm->enviar($this->leadId);
    }
}
