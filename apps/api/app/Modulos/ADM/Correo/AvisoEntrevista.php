<?php

namespace App\Modulos\ADM\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** EDUN-53 · Aviso a la familia: entrevista de admisión agendada. */
class AvisoEntrevista extends Mailable
{
    public function __construct(public string $colegio, public string $contacto, public string $postulante, public string $cuando, public ?string $lugar) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Entrevista de admisión en {$this->colegio}");
    }

    public function content(): Content
    {
        $e = fn ($t) => e((string) $t);

        return new Content(htmlString: '<p>Hola, '.$e($this->contacto).':</p><p>'.$e($this->colegio).' le confirma la entrevista de admisión de <strong>'.$e($this->postulante)
            .'</strong> el <strong>'.$e($this->cuando).'</strong>'.($this->lugar ? ' en '.$e($this->lugar) : '').'.</p><p>Si no puede asistir, comuníquese con el colegio.</p>');
    }
}
