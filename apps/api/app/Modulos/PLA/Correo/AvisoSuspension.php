<?php

namespace App\Modulos\PLA\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** EDUN-28 · Aviso al promotor: el servicio se suspenderá por falta de pago. */
class AvisoSuspension extends Mailable
{
    public function __construct(public string $colegio, public string $fecha, public int $dias, public ?string $motivo) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Edunova: el servicio de {$this->colegio} se suspenderá el {$this->fecha}");
    }

    public function content(): Content
    {
        $e = fn ($t) => e((string) $t);

        return new Content(htmlString: '<p>Estimado(a) promotor(a):</p><p>El servicio de Edunova de <strong>'.$e($this->colegio).'</strong> se suspenderá el <strong>'
            .$e($this->fecha).'</strong> (en '.$this->dias.' días)'.($this->motivo ? ' por: '.$e($this->motivo) : ' por falta de pago').'.</p>'
            .'<p>Si ya pagaste, responde a este correo con el comprobante. Mientras esté suspendido nadie podrá ingresar, pero <strong>los datos del colegio se conservan</strong> y puedes pedir una copia completa.</p>'
            .'<p>Edunova</p>');
    }
}
