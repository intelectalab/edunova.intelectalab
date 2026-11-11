<?php

namespace App\Modulos\SIT\Services\Seo;

use Illuminate\Support\Str;

/**
 * Proveedor «simulado»: arma sugerencias con plantillas a partir de lo que ya tiene la página.
 * Sirve para probar el flujo sin clave de IA y como respaldo; no llama a ningún servicio externo.
 */
class GeneradorSeoSimulado implements GeneradorSeo
{
    private const VACIAS = ['de', 'la', 'el', 'en', 'y', 'a', 'los', 'las', 'del', 'para', 'con', 'por', 'un', 'una', 'su', 'sus', 'que', 'tu', 'tus', 'al', 'o', 'es', 'se', 'lo', 'como', 'más'];

    public function sugerir(array $pagina, string $modelo): array
    {
        $tema = trim(Str::before($pagina['meta_titulo'], '|'));
        if ($tema === '' || mb_strtolower($tema) === 'edunova') {
            $tema = $pagina['slug'] === 'inicio' ? 'Software de gestión escolar' : Str::headline($pagina['slug']);
        }
        $base = rtrim(trim($pagina['meta_descripcion']), '.');
        $keywords = $this->palabrasClave($pagina);
        $clave = $keywords[0] ?? mb_strtolower($tema);

        $descripciones = array_map(fn (string $d) => $this->recortar($d), [
            "{$tema}: {$base}. Pide una demo gratuita y conoce Edunova.",
            "¿Buscas {$clave}? {$base}. Todo en una sola plataforma para tu colegio.",
            "{$base}. Matrícula, notas, pagos y comunicación con las familias en Edunova.",
        ]);

        return ['descripciones' => $descripciones, 'titulo' => Str::limit("{$tema} | Edunova", 60, ''), 'keywords' => $keywords, 'modelo' => null, 'tokens_entrada' => null, 'tokens_salida' => null];
    }

    /** @return list<string> */
    private function palabrasClave(array $pagina): array
    {
        $texto = mb_strtolower($pagina['meta_titulo'].' '.$pagina['meta_descripcion'].' '.($pagina['contexto'] ?? ''));
        $palabras = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $texto) ?: [], fn ($p) => mb_strlen($p) > 3 && $p !== 'edunova' && ! in_array($p, self::VACIAS, true)));
        $frecuentes = array_count_values($palabras);
        arsort($frecuentes);
        $sugeridas = array_slice(array_keys($frecuentes), 0, 6);
        $pares = [];
        for ($i = 0; $i + 1 < count($palabras) && count($pares) < 3; $i++) {
            $pares[] = $palabras[$i].' '.$palabras[$i + 1];
        }

        return array_values(array_unique(array_merge($pagina['keywords'], $pares, $sugeridas, ['software para colegios'])));
    }

    private function recortar(string $texto): string
    {
        $texto = preg_replace('/\s+/', ' ', trim($texto));
        if (mb_strlen($texto) <= 155) {
            return $texto;
        }
        $corte = mb_substr($texto, 0, 154);

        return rtrim(mb_substr($corte, 0, (int) mb_strrpos($corte, ' ')), ' ,.;:').'…';
    }
}
