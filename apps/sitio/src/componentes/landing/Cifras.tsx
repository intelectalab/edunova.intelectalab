'use client';

import { useEffect, useRef, useState } from 'react';
import { landing } from '@/contenido/provisional';
import type { Contador } from '@/lib/landing';

const DURACION_MS = 1200;

/**
 * EDUN-245 · Franja de cifras bajo el hero. Cada contador sube de 0 a su valor al entrar en
 * pantalla. Sin JavaScript o con prefers-reduced-motion se ve el valor final (el HTML del
 * servidor ya lo trae).
 */
export function Cifras({ contadores }: { contadores: Contador[] }) {
  const ref = useRef<HTMLElement>(null);
  const [progreso, setProgreso] = useState(1);

  useEffect(() => {
    const nodo = ref.current;
    if (!nodo || window.matchMedia('(prefers-reduced-motion: reduce)').matches || !('IntersectionObserver' in window)) return;
    setProgreso(0);
    let cuadro = 0;
    const observador = new IntersectionObserver(
      ([entrada]) => {
        if (!entrada.isIntersecting) return;
        observador.disconnect();
        const inicio = performance.now();
        const paso = (t: number) => {
          const p = Math.min(1, (t - inicio) / DURACION_MS);
          setProgreso(1 - Math.pow(1 - p, 3));
          if (p < 1) cuadro = requestAnimationFrame(paso);
        };
        cuadro = requestAnimationFrame(paso);
      },
      { threshold: 0.4 },
    );
    observador.observe(nodo);
    return () => {
      observador.disconnect();
      cancelAnimationFrame(cuadro);
    };
  }, []);

  if (contadores.length === 0) return null;

  return (
    <section ref={ref} aria-label={landing.cifras} className="w-full bg-surface py-space-xl">
      <ul className="max-w-7xl mx-auto px-margin-mobile md:px-margin grid grid-cols-2 lg:grid-cols-4 gap-space-md">
        {contadores.map((c) => {
          const actual = Math.round(c.valor * progreso);
          return (
            <li key={c.label} className="bg-surface-container-lowest rounded-xl shadow-sm p-space-lg flex flex-col gap-space-xs">
              <span className="font-display-lg text-headline-lg md:text-display-lg text-primary tabular-nums" aria-hidden="true">
                {c.prefijo}
                {actual}
                {c.sufijo}
              </span>
              <span className="sr-only">
                {c.prefijo}
                {c.valor}
                {c.sufijo} {c.label}
              </span>
              <span aria-hidden="true" className="font-body-sm text-body-sm text-secondary">
                {c.label}
              </span>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
