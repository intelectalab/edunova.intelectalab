<?php

namespace App\Modulos\Compartido\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo de prueba del comando correo:probar (EDUN-208). */
class CorreoDePrueba extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Correo de prueba de Edunova');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Si lees esto, el correo transaccional de Edunova funciona.</p>');
    }
}
