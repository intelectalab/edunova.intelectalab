<?php

namespace App\Modulos\PAG\Correo;

use App\Modulos\SEG\Models\Archivo;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/** EDUN-73 / EDUN-75 · Comprobante electrónico enviado al correo de la familia (PDF adjunto). */
class ComprobanteEmitido extends Mailable
{
    public function __construct(public string $colegio, public string $tipo, public string $numero, public float $total, public Archivo $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->tipo} {$this->numero} · {$this->colegio}");
    }

    public function content(): Content
    {
        $e = fn ($t) => e((string) $t);

        return new Content(htmlString: '<p>Hola:</p><p>'.$e($this->colegio).' le envía su '.$e(mb_strtolower($this->tipo)).' <strong>'.$e($this->numero)
            .'</strong> por <strong>S/ '.number_format($this->total, 2).'</strong>. La encontrará adjunta en PDF.</p><p>Gracias por su pago.</p>');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        $pdf = $this->pdf;

        return [Attachment::fromData(fn () => (string) Storage::disk($pdf->disco)->get($pdf->ruta), "{$this->numero}.pdf")->withMime('application/pdf')];
    }
}
