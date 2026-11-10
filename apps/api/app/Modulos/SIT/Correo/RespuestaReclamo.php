<?php

namespace App\Modulos\SIT\Correo;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** EDUN-302 · Respuesta al reclamante desde el Panel CMS (Ley N.° 29571, D.S. N.° 011-2011-PCM). */
class RespuestaReclamo extends Mailable
{
    /** @param  array<string, mixed>  $r  expediente, tipo, nombre, respuesta, url_seguimiento */
    public function __construct(public array $r) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Respuesta a tu {$this->r['tipo']} {$this->r['expediente']}");
    }

    public function content(): Content
    {
        $r = array_map(fn ($v) => e((string) $v), $this->r);
        $respuesta = nl2br($r['respuesta']);

        return new Content(htmlString: <<<HTML
            <p>Hola, {$r['nombre']}:</p>
            <p>Te respondemos sobre tu <strong>{$r['tipo']}</strong> registrada en el Libro de Reclamaciones virtual de Edunova con el número
            <strong>{$r['expediente']}</strong>.</p>
            <div style="border-left:4px solid #047857;padding:8px 16px;margin:16px 0">{$respuesta}</div>
            <p>Puedes consultar el estado del expediente en <a href="{$r['url_seguimiento']}">{$r['url_seguimiento']}</a> con tu número de expediente y tu documento.</p>
            <p>Edunova</p>
            HTML);
    }
}
