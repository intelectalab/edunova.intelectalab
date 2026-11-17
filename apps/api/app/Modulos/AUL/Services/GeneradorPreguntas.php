<?php

namespace App\Modulos\AUL\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Modulos\SIT\Services\AsistenteSeo;
use App\Modulos\SIT\Services\Integraciones;
use Illuminate\Validation\ValidationException;

/**
 * EDUN-129 · Preguntas sugeridas por la IA para el banco del docente.
 *
 * Con la clave de IA configurada en el Panel CMS (o ANTHROPIC_API_KEY en el servidor) se usa
 * Claude con salida estructurada; sin clave, un generador simulado arma preguntas de ejemplo para
 * probar el flujo. En los dos casos las preguntas llegan marcadas «IA» e inactivas: el docente
 * las revisa antes de usarlas.
 */
class GeneradorPreguntas
{
    private const SISTEMA = <<<'TXT'
        Eres docente experto en el Currículo Nacional de Educación Básica del Perú. Redactas preguntas de evaluación claras, en español del Perú, adecuadas al grado y nivel indicados.
        Reglas:
        - Mezcla tipos: opcion_multiple (4 opciones, una sola correcta), verdadero_falso y respuesta_corta (respuestas de una o dos palabras).
        - Cada pregunta evalúa la competencia y el tema indicados; no inventes datos dudosos.
        - En opcion_multiple, respuesta_correcta es el índice (desde 0) de la opción correcta y la posición de la correcta varía.
        - En verdadero_falso, respuesta_correcta es true o false y opciones va vacío.
        - En respuesta_corta, respuesta_correcta es una lista de respuestas aceptadas y opciones va vacío.
        - retroalimentacion explica en una oración por qué la respuesta es la correcta.
        TXT;

    private const ESQUEMA = [
        'type' => 'object',
        'properties' => [
            'preguntas' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'tipo' => ['type' => 'string', 'enum' => ['opcion_multiple', 'verdadero_falso', 'respuesta_corta']],
                    'enunciado' => ['type' => 'string'],
                    'opciones' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'respuesta_opcion' => ['type' => 'integer'],
                    'respuesta_vf' => ['type' => 'boolean'],
                    'respuestas_cortas' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'retroalimentacion' => ['type' => 'string'],
                ],
                'required' => ['tipo', 'enunciado', 'opciones', 'respuesta_opcion', 'respuesta_vf', 'respuestas_cortas', 'retroalimentacion'],
                'additionalProperties' => false,
            ]],
        ],
        'required' => ['preguntas'],
        'additionalProperties' => false,
    ];

    public function __construct(private ?Client $cliente = null) {}

    public static function proveedor(): string
    {
        return Integraciones::claveIa() ? 'claude' : 'simulado';
    }

    /**
     * @param  array{area: string, grado: string, nivel: string, competencia: ?string, tema: string}  $contexto
     * @return list<array{tipo: string, enunciado: string, opciones?: mixed, respuesta_correcta: mixed, retroalimentacion?: string}>
     */
    public function generar(array $contexto, int $cantidad): array
    {
        $clave = Integraciones::claveIa();
        if (! $this->cliente && ! $clave) {
            return $this->simuladas($contexto, $cantidad);
        }
        $cliente = $this->cliente ?? new Client(apiKey: $clave);
        $pedido = "Área: {$contexto['area']}\nGrado: {$contexto['grado']} de {$contexto['nivel']}\nCompetencia: ".($contexto['competencia'] ?? 'la que mejor corresponda al tema')
            ."\nTema: {$contexto['tema']}\nCantidad de preguntas: {$cantidad}";
        try {
            $m = $cliente->beta->messages->create(
                model: AsistenteSeo::MODELOS[0],
                maxTokens: 16000,
                system: self::SISTEMA,
                messages: [['role' => 'user', 'content' => $pedido]],
                outputConfig: ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => self::ESQUEMA]],
            );
        } catch (AuthenticationException) {
            throw ValidationException::withMessages(['ia' => ['La clave de la IA no es válida. Revísala en el Panel CMS.']]);
        } catch (RateLimitException) {
            throw ValidationException::withMessages(['ia' => ['La IA está saturada en este momento. Intenta en un minuto.']]);
        } catch (APIConnectionException|APIStatusException $e) {
            report($e);
            throw ValidationException::withMessages(['ia' => ['La IA no respondió. Intenta otra vez en unos minutos.']]);
        }
        if ($m->stopReason === 'refusal') {
            throw ValidationException::withMessages(['ia' => ['La IA no generó preguntas para ese tema. Revisa el tema o escríbelas a mano.']]);
        }
        $texto = '';
        foreach ($m->content as $b) {
            if ($b->type === 'text') {
                $texto = $b->text;
                break;
            }
        }
        $r = json_decode($texto, true);

        return collect($r['preguntas'] ?? [])->take($cantidad)->map(fn ($p) => match ($p['tipo'] ?? '') {
            'opcion_multiple' => ['tipo' => 'opcion_multiple', 'enunciado' => $p['enunciado'], 'opciones' => array_values($p['opciones']), 'respuesta_correcta' => (int) $p['respuesta_opcion'], 'retroalimentacion' => $p['retroalimentacion']],
            'verdadero_falso' => ['tipo' => 'verdadero_falso', 'enunciado' => $p['enunciado'], 'respuesta_correcta' => (bool) $p['respuesta_vf'], 'retroalimentacion' => $p['retroalimentacion']],
            default => ['tipo' => 'respuesta_corta', 'enunciado' => $p['enunciado'], 'respuesta_correcta' => array_values($p['respuestas_cortas']), 'retroalimentacion' => $p['retroalimentacion']],
        })->values()->all();
    }

    /** Preguntas de ejemplo (sin IA) para probar la revisión y el banco. */
    private function simuladas(array $c, int $cantidad): array
    {
        $tema = $c['tema'] ?: $c['area'];
        $r = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            $r[] = match ($i % 3) {
                1 => ['tipo' => 'opcion_multiple', 'enunciado' => "[Ejemplo sin IA] Pregunta {$i} sobre {$tema} para {$c['grado']}: ¿cuál es la opción correcta?",
                    'opciones' => ['Primera opción', 'Segunda opción (correcta)', 'Tercera opción', 'Cuarta opción'], 'respuesta_correcta' => 1, 'retroalimentacion' => 'Pregunta de ejemplo: reemplázala por una real.'],
                2 => ['tipo' => 'verdadero_falso', 'enunciado' => "[Ejemplo sin IA] Afirmación {$i} sobre {$tema}.", 'respuesta_correcta' => true, 'retroalimentacion' => 'Pregunta de ejemplo.'],
                default => ['tipo' => 'respuesta_corta', 'enunciado' => "[Ejemplo sin IA] Escribe una palabra clave de {$tema}.", 'respuesta_correcta' => [mb_strtolower(explode(' ', $tema)[0])], 'retroalimentacion' => 'Pregunta de ejemplo.'],
            };
        }

        return $r;
    }
}
