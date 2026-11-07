<?php

namespace App\Modulos\SIT\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Facades\DB;

/**
 * EDUN-287 / SIT-053 · Políticas del sitio (privacidad, términos, seguridad y cookies).
 * Siempre vale la versión vigente: la de vigente_desde más reciente que ya empezó (hora de Lima).
 * Debe llamarse dentro de una transacción con app.rol_cms fijado.
 */
class Politicas
{
    public const TIPOS = ['privacidad', 'terminos', 'seguridad', 'cookies'];

    public const TITULOS = [
        'privacidad' => 'Política de Privacidad',
        'terminos' => 'Términos y Condiciones del servicio',
        'seguridad' => 'Seguridad de Datos (LPDP)',
        'cookies' => 'Política de Cookies',
    ];

    private const A_CONFIRMAR = '[A CONFIRMAR]';

    private const ETIQUETAS = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'a', 'br'];

    /** Fila vigente o null si el tipo aún no tiene versión en vigor. */
    public function vigente(string $tipo): ?object
    {
        return DB::table('cms.politicas')
            ->where('tipo', $tipo)
            ->where('vigente_desde', '<=', now('America/Lima')->toDateString())
            ->orderByDesc('vigente_desde')->orderByDesc('created_at')->orderByDesc('id')
            ->first(['tipo', 'version', 'contenido', 'vigente_desde', 'borrador']);
    }

    /** Versión que se guarda en el consentimiento, p. ej. «privacidad-v1.0». */
    public function claveVigente(string $tipo): ?string
    {
        $p = $this->vigente($tipo);

        return $p ? "{$tipo}-v{$p->version}" : null;
    }

    /** La política lista para mostrar: datos de la empresa reemplazados y HTML limpio. */
    public function publica(string $tipo): ?array
    {
        $p = $this->vigente($tipo);
        if (! $p) {
            return null;
        }

        return [
            'tipo' => $p->tipo,
            'titulo' => self::TITULOS[$p->tipo],
            'version' => $p->version,
            'vigente_desde' => $p->vigente_desde,
            'borrador' => (bool) $p->borrador,
            'contenido' => $this->limpiar($this->reemplazarDatos($p->contenido)),
        ];
    }

    private function reemplazarDatos(string $html): string
    {
        $config = DB::table('cms.config')->whereIn('clave', ['razon_social', 'ruc', 'direccion', 'email_contacto'])->pluck('valor', 'clave')
            ->map(fn ($v) => json_decode($v, true));

        return preg_replace_callback('/\{\{(razon_social|ruc|direccion|email_contacto)\}\}/', function ($m) use ($config) {
            $valor = $config->get($m[1]);

            return is_string($valor) && trim($valor) !== '' ? e($valor) : self::A_CONFIRMAR;
        }, $html);
    }

    /** Solo etiquetas de texto; enlaces internos, https o mailto. Sin atributos ni scripts. */
    public function limpiar(string $html): string
    {
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="raiz">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $raiz = $doc->getElementById('raiz') ?? $doc->documentElement;
        $this->limpiarNodo($raiz);

        $salida = '';
        foreach (iterator_to_array($raiz->childNodes) as $hijo) {
            $salida .= $doc->saveHTML($hijo);
        }

        return trim($salida);
    }

    private function limpiarNodo(DOMNode $nodo): void
    {
        foreach (iterator_to_array($nodo->childNodes) as $hijo) {
            if (! $hijo instanceof DOMElement) {
                if ($hijo->nodeType === XML_COMMENT_NODE || $hijo->nodeType === XML_PI_NODE) {
                    $nodo->removeChild($hijo);
                }

                continue;
            }

            $etiqueta = strtolower($hijo->tagName);
            if (in_array($etiqueta, ['script', 'style', 'iframe', 'object', 'embed', 'template'], true)) {
                $nodo->removeChild($hijo);

                continue;
            }

            $this->limpiarNodo($hijo);

            if (! in_array($etiqueta, self::ETIQUETAS, true)) {
                // Etiqueta no permitida: se queda su texto.
                while ($hijo->firstChild) {
                    $nodo->insertBefore($hijo->firstChild, $hijo);
                }
                $nodo->removeChild($hijo);

                continue;
            }

            $href = $etiqueta === 'a' ? trim($hijo->getAttribute('href')) : '';
            foreach (iterator_to_array($hijo->attributes) as $atributo) {
                $hijo->removeAttribute($atributo->nodeName);
            }
            if ($etiqueta === 'a' && preg_match('#^(/(?!/)|https://|mailto:)#i', $href)) {
                $hijo->setAttribute('href', $href);
                if (str_starts_with(strtolower($href), 'https://')) {
                    $hijo->setAttribute('rel', 'noopener');
                    $hijo->setAttribute('target', '_blank');
                }
            }
        }
    }
}
