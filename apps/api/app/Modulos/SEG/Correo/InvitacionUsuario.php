<?php

namespace App\Modulos\SEG\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** EDUN-36 / EDUN-24 · Invitación a Edunova con enlace de un solo uso. */
class InvitacionUsuario extends Mailable
{
    /** @param  array{nombre: string, colegio: string, roles: string, url: string, vence: string}  $d */
    public function __construct(public array $d) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Te invitaron a {$this->d['colegio']} en Edunova");
    }

    public function content(): Content
    {
        $d = array_map(fn ($v) => e((string) $v), $this->d);

        return new Content(htmlString: <<<HTML
            <p>Hola, {$d['nombre']}:</p>
            <p><strong>{$d['colegio']}</strong> te invitó a Edunova como <strong>{$d['roles']}</strong>.</p>
            <p>Para entrar, abre este enlace y crea tu contraseña:</p>
            <p><a href="{$d['url']}">{$d['url']}</a></p>
            <p>El enlace sirve una sola vez y vence el {$d['vence']}. Si no esperabas esta invitación, puedes ignorar este correo.</p>
            <p>Edunova</p>
            HTML);
    }
}
