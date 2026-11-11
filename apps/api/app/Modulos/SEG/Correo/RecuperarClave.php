<?php

namespace App\Modulos\SEG\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Enlace de un solo uso para crear una contraseña nueva. */
class RecuperarClave extends Mailable
{
    /** @param  array{nombre: string, url: string, minutos: int}  $d */
    public function __construct(public array $d) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Crea una nueva contraseña en Edunova');
    }

    public function content(): Content
    {
        $d = array_map(fn ($v) => e((string) $v), $this->d);

        return new Content(htmlString: <<<HTML
            <p>Hola, {$d['nombre']}:</p>
            <p>Pediste crear una nueva contraseña para entrar a Edunova. Abre este enlace:</p>
            <p><a href="{$d['url']}">Crear mi nueva contraseña</a></p>
            <p>El enlace sirve una sola vez y vence en {$d['minutos']} minutos. Si no lo pediste, ignora este correo: tu contraseña no cambia.</p>
            <p>Edunova</p>
            HTML);
    }
}
