<?php

namespace App\Modulos\SIT\Services\Seo;

/**
 * EDUN-412 / SIT-030 · Proveedor de sugerencias SEO (D-SIT-04: configurable desde el CMS).
 *
 * Recibe los datos de la página y devuelve tres opciones de meta descripción (hasta 155
 * caracteres), un título sugerido y palabras clave. Nunca guarda nada: el editor elige y publica
 * con el flujo normal de Ajustes SEO (que deja versión y auditoría).
 */
interface GeneradorSeo
{
    /**
     * @param  array{slug: string, meta_titulo: string, meta_descripcion: string, keywords: list<string>, contexto: ?string}  $pagina
     * @return array{descripciones: list<string>, titulo: string, keywords: list<string>, modelo: ?string, tokens_entrada: ?int, tokens_salida: ?int}
     */
    public function sugerir(array $pagina, string $modelo): array;
}
