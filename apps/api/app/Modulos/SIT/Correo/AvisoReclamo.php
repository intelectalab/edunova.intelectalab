<?php

namespace App\Modulos\SIT\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** EDUN-294 · Aviso interno al equipo de soporte: llegó un reclamo o una queja. */
class AvisoReclamo extends Mailable
{
    /** @param  array<string, mixed>  $r  expediente, tipo, nombre, email, telefono, producto, descripcion, pedido, vence_texto, adjuntos */
    public function __construct(public array $r) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Nuevo {$this->r['tipo']} {$this->r['expediente']} · vence el {$this->r['vence_texto']}");
    }

    public function content(): Content
    {
        $r = array_map(fn ($v) => e((string) $v), $this->r);

        return new Content(htmlString: <<<HTML
            <p>Llegó un <strong>{$r['tipo']}</strong> al Libro de Reclamaciones: <strong>{$r['expediente']}</strong>.</p>
            <p><strong>Vence:</strong> {$r['vence_texto']} (15 días hábiles).</p>
            <p><strong>Reclamante:</strong> {$r['nombre']} · {$r['email']} · {$r['telefono']}<br>
            <strong>Bien o servicio:</strong> {$r['producto']}<br>
            <strong>Detalle:</strong> {$r['descripcion']}<br>
            <strong>Pedido:</strong> {$r['pedido']}<br>
            <strong>Adjuntos:</strong> {$r['adjuntos']}</p>
            HTML);
    }
}
