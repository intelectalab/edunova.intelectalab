<?php

namespace App\Modulos\SIT\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * EDUN-294 · Correo de confirmación al reclamante: número de expediente y plazo de respuesta
 * de 15 días hábiles (Ley N.° 29571 y D.S. N.° 011-2011-PCM).
 */
class ConfirmacionReclamo extends Mailable
{
    /** @param  array<string, mixed>  $r  expediente, tipo, nombre, vence_en, producto, descripcion, pedido, url_seguimiento */
    public function __construct(public array $r) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Recibimos tu {$this->r['tipo']} {$this->r['expediente']}");
    }

    public function content(): Content
    {
        $r = array_map(fn ($v) => e((string) $v), $this->r);

        return new Content(htmlString: <<<HTML
            <p>Hola, {$r['nombre']}:</p>
            <p>Registramos tu <strong>{$r['tipo']}</strong> en el Libro de Reclamaciones virtual de Edunova con el número
            <strong>{$r['expediente']}</strong>.</p>
            <p>Te responderemos a este correo en un plazo máximo de <strong>15 días hábiles</strong>, a más tardar el
            <strong>{$r['vence_texto']}</strong>.</p>
            <p><strong>Bien o servicio:</strong> {$r['producto']}<br><strong>Detalle:</strong> {$r['descripcion']}<br><strong>Pedido:</strong> {$r['pedido']}</p>
            <p>Puedes consultar el estado en <a href="{$r['url_seguimiento']}">{$r['url_seguimiento']}</a> con tu número de expediente y tu documento.</p>
            <p>Edunova</p>
            HTML);
    }
}
