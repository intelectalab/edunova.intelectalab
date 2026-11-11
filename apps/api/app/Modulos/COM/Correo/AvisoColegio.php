<?php

namespace App\Modulos\COM\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Aviso del colegio por correo (comunicados, recordatorios, cobranza y libro de reclamaciones). */
class AvisoColegio extends Mailable
{
    public function __construct(public string $colegio, public string $titulo, public string $cuerpo) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->titulo} · {$this->colegio}");
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>'.nl2br(e($this->cuerpo)).'</p><p style="color:#64748b">— '.e($this->colegio).'</p>');
    }
}
