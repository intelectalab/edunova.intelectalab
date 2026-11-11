<?php

namespace App\Modulos\SIT\Services\Seo;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Validation\ValidationException;

/**
 * Proveedor «claude»: pide las sugerencias a la API de Claude con salida estructurada (JSON
 * validado contra un esquema). La clave sale del entorno del servidor (ANTHROPIC_API_KEY), nunca
 * de la base ni del CMS. Si la IA declina por política, se intenta con el modelo de respaldo que
 * define el servidor («fallbacks: default»).
 */
class GeneradorSeoClaude implements GeneradorSeo
{
    private const SISTEMA = <<<'TXT'
        Eres especialista en SEO para el mercado peruano. Edunova es un software de gestión escolar (SaaS) para colegios privados del Perú: matrícula, notas por competencias, asistencia, pagos y pensiones, comunicación con las familias, integración con SIAGIE y app móvil.
        Escribe en español del Perú, claro y sin exageraciones. No inventes cifras, premios, clientes ni funciones que no estén en los datos de la página. No uses comillas ni emojis.
        Devuelve:
        - descripciones: exactamente 3 opciones de meta descripción distintas entre sí, de 120 a 155 caracteres cada una, con la palabra clave principal al inicio y una llamada a la acción.
        - titulo: un título SEO de hasta 60 caracteres que termine en «| Edunova».
        - keywords: de 5 a 10 palabras clave o frases cortas en minúsculas, ordenadas de más a menos relevante, como las buscaría un director o promotor de colegio.
        TXT;

    private const ESQUEMA = [
        'type' => 'object',
        'properties' => [
            'descripciones' => ['type' => 'array', 'items' => ['type' => 'string']],
            'titulo' => ['type' => 'string'],
            'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['descripciones', 'titulo', 'keywords'],
        'additionalProperties' => false,
    ];

    public function __construct(private ?Client $cliente = null) {}

    public function sugerir(array $pagina, string $modelo): array
    {
        $clave = config('services.anthropic.clave');
        if (! $this->cliente && ! $clave) {
            throw ValidationException::withMessages(['proveedor' => ['Falta configurar la clave de la IA en el servidor. Usa el proveedor «simulado» mientras tanto.']]);
        }
        $cliente = $this->cliente ?? new Client(apiKey: $clave);
        $datos = json_encode([
            'pagina' => $pagina['slug'], 'titulo_actual' => $pagina['meta_titulo'], 'descripcion_actual' => $pagina['meta_descripcion'],
            'keywords_actuales' => $pagina['keywords'], 'contexto_del_editor' => $pagina['contexto'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        try {
            $m = $cliente->beta->messages->create(
                model: $modelo,
                maxTokens: 8000,
                system: self::SISTEMA,
                messages: [['role' => 'user', 'content' => "Datos de la página:\n{$datos}"]],
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::ESQUEMA]],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException) {
            throw ValidationException::withMessages(['proveedor' => ['La clave de la IA no es válida. Revísala en el servidor.']]);
        } catch (RateLimitException) {
            throw ValidationException::withMessages(['proveedor' => ['La IA está saturada en este momento. Intenta en un minuto.']]);
        } catch (APIConnectionException|APIStatusException $e) {
            report($e);
            throw ValidationException::withMessages(['proveedor' => ['La IA no respondió. Intenta otra vez en unos minutos.']]);
        }

        if ($m->stopReason === 'refusal') {
            throw ValidationException::withMessages(['proveedor' => ['La IA no generó sugerencias para este contenido. Revisa el contexto o escríbelas a mano.']]);
        }
        $texto = '';
        foreach ($m->content as $bloque) {
            if ($bloque->type === 'text') {
                $texto = $bloque->text;
                break;
            }
        }
        $r = json_decode($texto, true);
        if (! is_array($r) || ! isset($r['descripciones'], $r['titulo'], $r['keywords'])) {
            throw ValidationException::withMessages(['proveedor' => ['La respuesta de la IA llegó incompleta. Intenta otra vez.']]);
        }

        return [
            'descripciones' => array_values(array_slice(array_filter(array_map('trim', (array) $r['descripciones']), 'strlen'), 0, 3)),
            'titulo' => trim((string) $r['titulo']),
            'keywords' => array_values(array_unique(array_slice(array_map(fn ($k) => mb_strtolower(trim((string) $k)), (array) $r['keywords']), 0, 10))),
            'modelo' => $m->model,
            'tokens_entrada' => $m->usage->inputTokens ?? null,
            'tokens_salida' => $m->usage->outputTokens ?? null,
        ];
    }
}
