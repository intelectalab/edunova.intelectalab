'use client';

import { useId, useRef, useState, type KeyboardEvent } from 'react';
import { Icono } from '@/componentes/base';
import type { SeccionCasos } from '@/lib/landing';

type Tarjeta = SeccionCasos['tarjetas'][number];

/** Pestañas accesibles (flechas, Inicio y Fin) con un panel por tipo de institución. */
export function PestanasColegios({ etiqueta, tarjetas, modulosSugeridos, pedirDemo }: { etiqueta: string; tarjetas: Tarjeta[]; modulosSugeridos: string; pedirDemo: string }) {
  const [activa, setActiva] = useState(0);
  const base = useId();
  const botones = useRef<(HTMLButtonElement | null)[]>([]);
  if (tarjetas.length === 0) return null;

  function teclado(e: KeyboardEvent) {
    const n = tarjetas.length;
    const destino = e.key === 'ArrowRight' ? (activa + 1) % n : e.key === 'ArrowLeft' ? (activa - 1 + n) % n : e.key === 'Home' ? 0 : e.key === 'End' ? n - 1 : null;
    if (destino === null) return;
    e.preventDefault();
    setActiva(destino);
    botones.current[destino]?.focus();
  }

  const c = tarjetas[activa];
  return (
    <div className="flex flex-col gap-space-md">
      <div role="tablist" aria-label={etiqueta} className="flex flex-wrap gap-space-xs" onKeyDown={teclado}>
        {tarjetas.map((x, i) => (
          <button
            key={x.titulo}
            ref={(el) => {
              botones.current[i] = el;
            }}
            id={`${base}-pestana-${i}`}
            role="tab"
            type="button"
            aria-selected={i === activa}
            aria-controls={`${base}-panel-${i}`}
            tabIndex={i === activa ? 0 : -1}
            onClick={() => setActiva(i)}
            className={`inline-flex items-center gap-space-xs px-space-md py-space-sm rounded-lg font-label-lg text-label-lg ${i === activa ? 'bg-primary text-on-primary' : 'bg-surface-container-low text-primary hover:bg-surface-container-high'}`}
          >
            <Icono nombre={x.icono} tamano="sm" />
            {x.titulo}
          </button>
        ))}
      </div>
      <section role="tabpanel" id={`${base}-panel-${activa}`} aria-labelledby={`${base}-pestana-${activa}`} className="grid gap-space-lg md:grid-cols-[1fr_18rem] p-space-lg rounded-2xl bg-surface-container-lowest border border-surface-container">
        <div className="flex flex-col gap-space-sm">
          <span className="font-label-md text-label-md text-secondary uppercase tracking-wider">{c.rango}</span>
          <h2 className="font-headline-md text-headline-md text-primary">{c.titulo}</h2>
          <p className="font-body-lg text-body-lg text-secondary">{c.descripcion}</p>
          <ul className="flex flex-col gap-1">
            {c.beneficios.map((b) => (
              <li key={b} className="flex items-start gap-space-xs font-body-md text-body-md text-on-surface">
                <Icono nombre="check_circle" tamano="sm" className="text-accion mt-0.5" />
                {b}
              </li>
            ))}
          </ul>
          {c.nota && <p className="font-label-md text-label-md text-on-surface-variant">{c.nota}</p>}
        </div>
        <div className="flex flex-col gap-space-sm">
          {c.modulos && (
            <div className="p-space-md rounded-xl bg-surface-container-low">
              <h3 className="font-title-md text-title-md text-on-surface mb-1">{modulosSugeridos}</h3>
              <p className="font-body-sm text-body-sm text-secondary">{c.modulos}</p>
            </div>
          )}
          <a href="#demo" className="text-center px-space-md py-space-sm rounded-lg bg-accion hover:bg-accion-hover text-accion-texto font-label-lg text-label-lg">
            {pedirDemo}
          </a>
        </div>
      </section>
    </div>
  );
}
