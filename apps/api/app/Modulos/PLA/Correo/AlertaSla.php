<?php

namespace App\Modulos\PLA\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/** EDUN-31 · Aviso interno al staff: ticket al 75 % del plazo o vencido (escalado). */
class AlertaSla extends Mailable
{
    /** @param array{meta: string|null, porcentaje: int|null, vence: string|null} $semaforo */
    public function __construct(public string $tipo, public string $numero, public string $colegio, public string $asunto, public string $prioridad, public array $semaforo) {}

    public function envelope(): Envelope
    {
        $que = $this->tipo === 'escalado' ? 'VENCIDO' : 'al 75 %';

        return new Envelope(subject: "[SLA {$que}] {$this->numero} · {$this->colegio}");
    }

    public function content(): Content
    {
        $e = fn ($t) => e((string) $t);
        $meta = $this->semaforo['meta'] === 'primera_respuesta' ? 'primera respuesta' : 'resolución';
        $vence = $this->semaforo['vence'] ? Carbon::parse($this->semaforo['vence'])->timezone('America/Lima')->format('d/m/Y H:i') : '—';
        $texto = $this->tipo === 'escalado'
            ? "venció el plazo de <strong>{$meta}</strong> ({$vence}). Se escala para atención inmediata."
            : "ya usó el 75 % del plazo de <strong>{$meta}</strong>; vence el {$vence}.";

        return new Content(htmlString: '<p>El ticket <strong>'.$e($this->numero).'</strong> de '.$e($this->colegio).' (prioridad '.$e($this->prioridad).') '
            .$texto.'</p><p><strong>Asunto:</strong> '.$e($this->asunto).'</p><p>Edunova · Mesa de ayuda</p>');
    }
}
