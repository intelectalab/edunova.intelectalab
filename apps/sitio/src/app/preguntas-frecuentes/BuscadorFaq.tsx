'use client';

import { useId, useMemo, useState } from 'react';
import { Icono } from '@/componentes/base';
import { paginas } from '@/contenido/provisional';

export type Pregunta = { pregunta: string; respuesta: string; categoria?: string | null };

/** Quita tildes y mayúsculas para buscar «siagie» y encontrar «SIAGIE», «pensión» con «pension». */
export function normalizar(texto: string): string {
  return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

/** Preguntas que contienen todas las palabras buscadas (en la pregunta o en la respuesta). */
export function filtrarPreguntas(preguntas: Pregunta[], consulta: string, categoria: string): Pregunta[] {
  const palabras = normalizar(consulta).split(/\s+/).filter(Boolean);
  return preguntas.filter((p) => {
    if (categoria && (p.categoria || paginas.faq.general) !== categoria) return false;
    const texto = normalizar(`${p.pregunta} ${p.respuesta}`);
    return palabras.every((w) => texto.includes(w));
  });
}

/**
 * EDUN-371 / SIT-007 · Acordeón de preguntas frecuentes con búsqueda en tiempo real (sin
 * recargar) y filtro por categoría.
 */
export function BuscadorFaq({ preguntas }: { preguntas: Pregunta[] }) {
  const t = paginas.faq;
  const [consulta, setConsulta] = useState('');
  const [categoria, setCategoria] = useState('');
  const id = useId();
  const categorias = useMemo(() => [...new Set(preguntas.map((p) => p.categoria || t.general))], [preguntas, t.general]);
  const visibles = filtrarPreguntas(preguntas, consulta, categoria);

  if (preguntas.length === 0) return <p className="font-body-lg text-body-lg text-on-surface-variant">{t.sinPreguntas}</p>;

  return (
    <div className="flex flex-col gap-space-md">
      <div role="search" className="flex flex-col gap-space-sm">
        <label htmlFor={`${id}-q`} className="font-label-lg text-label-lg text-on-surface">
          {t.buscar}
        </label>
        <div className="relative max-w-xl">
          <Icono nombre="search" className="absolute left-3 top-1/2 -translate-y-1/2 text-secondary" tamano="sm" />
          <input
            id={`${id}-q`}
            type="search"
            value={consulta}
            onChange={(e) => setConsulta(e.target.value)}
            placeholder={t.buscarEjemplo}
            className="w-full h-12 pl-10 pr-3 rounded-lg border border-outline-variant bg-surface-container-lowest font-body-md text-body-md text-on-surface"
          />
        </div>
        {categorias.length > 1 && (
          <div role="group" aria-label={t.categorias} className="flex flex-wrap gap-space-xs">
            {['', ...categorias].map((c) => (
              <button
                key={c || 'todas'}
                type="button"
                aria-pressed={categoria === c}
                onClick={() => setCategoria(c)}
                className={`px-space-md py-space-xs rounded-full font-label-md text-label-md ${categoria === c ? 'bg-primary text-on-primary' : 'bg-surface-container-low text-primary hover:bg-surface-container-high'}`}
              >
                {c || t.todas}
              </button>
            ))}
          </div>
        )}
      </div>
      <p aria-live="polite" className="font-label-md text-label-md text-secondary">
        {visibles.length === 0 ? t.sinResultados : t.resultados(visibles.length)}
      </p>
      <div className="flex flex-col gap-space-xs">
        {visibles.map((p) => (
          <details key={p.pregunta} className="group rounded-xl bg-surface-container-lowest border border-surface-container">
            <summary className="flex items-center justify-between gap-space-sm cursor-pointer list-none p-space-md font-title-md text-title-md text-on-surface">
              {p.pregunta}
              <Icono nombre="expand_more" className="shrink-0 text-primary transition-transform group-open:rotate-180" />
            </summary>
            <p className="px-space-md pb-space-md font-body-md text-body-md text-secondary whitespace-pre-line">{p.respuesta}</p>
          </details>
        ))}
      </div>
    </div>
  );
}
