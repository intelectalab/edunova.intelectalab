'use client';

import { useRef, type KeyboardEvent, type PointerEvent } from 'react';
import { Icono } from '@/componentes/base';
import { cms } from '@/contenido/provisional';
import { acotar, ALTO_NODO, ANCHO_NODO, aristas, posiciones, trazo } from '@/lib/flujoChatbot';
import type { NodoChatbot } from '@/lib/landing';

const t = cms.chatbotCms;

export const ICONOS: Record<string, string> = { mensaje: 'chat_bubble', opciones: 'list', captura: 'contact_mail', url: 'link', whatsapp: 'chat', fin: 'flag' };

/**
 * EDUN-381 / SIT-028 · Lienzo del constructor visual: cada paso es una tarjeta que se arrastra
 * (o se mueve con las flechas del teclado) y las flechas muestran a dónde lleva cada mensaje u
 * opción. Elegir una tarjeta abre su edición al costado. En 390 px el lienzo se desplaza dentro
 * de su marco, sin mover la página.
 */
export function LienzoFlujo({ nodos, seleccionado, avisos, onSeleccionar, onMover }: {
  nodos: (Omit<NodoChatbot, 'url'> & { url: string | null })[];
  seleccionado: number | null;
  avisos: Set<number>;
  onSeleccionar: (i: number) => void;
  onMover: (i: number, p: { x: number; y: number }) => void;
}) {
  const pos = posiciones(nodos);
  const flechas = aristas(nodos);
  const arrastre = useRef<{ i: number; dx: number; dy: number; movio: boolean } | null>(null);
  const ancho = Math.max(640, ...pos.map((p) => p.x + ANCHO_NODO + 80));
  const alto = Math.max(360, ...pos.map((p) => p.y + ALTO_NODO + 80));

  const empezar = (i: number) => (e: PointerEvent<HTMLButtonElement>) => {
    if (e.button !== 0) return;
    e.currentTarget.setPointerCapture(e.pointerId);
    arrastre.current = { i, dx: e.clientX - pos[i].x, dy: e.clientY - pos[i].y, movio: false };
  };
  const mover = (e: PointerEvent<HTMLButtonElement>) => {
    const a = arrastre.current;
    if (!a) return;
    const p = acotar({ x: e.clientX - a.dx, y: e.clientY - a.dy });
    if (!a.movio && Math.abs(p.x - pos[a.i].x) + Math.abs(p.y - pos[a.i].y) < 4) return;
    a.movio = true;
    onMover(a.i, p);
  };
  const soltar = (i: number) => () => {
    const a = arrastre.current;
    arrastre.current = null;
    if (!a?.movio) onSeleccionar(i);
  };
  const teclado = (i: number) => (e: KeyboardEvent<HTMLButtonElement>) => {
    const paso = e.shiftKey ? 80 : 20;
    const d = { ArrowLeft: [-paso, 0], ArrowRight: [paso, 0], ArrowUp: [0, -paso], ArrowDown: [0, paso] }[e.key];
    if (!d) return;
    e.preventDefault();
    onMover(i, acotar({ x: pos[i].x + d[0], y: pos[i].y + d[1] }));
  };

  return (
    <div className="relative w-full overflow-auto rounded-xl border border-surface-container bg-surface-container-low max-h-[70vh]" role="group" aria-label={t.lienzo} aria-describedby="ayuda-lienzo">
      <p id="ayuda-lienzo" className="sr-only">
        {t.lienzoAyuda}
      </p>
      <div className="relative" style={{ width: ancho, height: alto, backgroundImage: 'radial-gradient(circle, rgb(0 0 0 / 0.08) 1px, transparent 1px)', backgroundSize: '20px 20px' }}>
        <svg className="absolute inset-0 pointer-events-none" width={ancho} height={alto} aria-hidden="true">
          <defs>
            <marker id="punta" viewBox="0 0 10 10" refX="5" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
              <path d="M 0 0 L 10 5 L 0 10 z" className="fill-secondary" />
            </marker>
          </defs>
          {flechas.map((f, k) => {
            const d = trazo(pos[f.desde], pos[f.hasta]);
            const medio = { x: (pos[f.desde].x + pos[f.hasta].x) / 2 + ANCHO_NODO / 2, y: (pos[f.desde].y + ALTO_NODO + pos[f.hasta].y) / 2 };
            return (
              <g key={k}>
                <path d={d} fill="none" strokeWidth={f.desde === seleccionado ? 2.5 : 1.5} className={f.desde === seleccionado ? 'stroke-primary' : 'stroke-secondary'} markerEnd="url(#punta)" />
                {f.etiqueta && (
                  <text x={medio.x} y={medio.y} textAnchor="middle" className="fill-on-surface-variant text-[11px]" paintOrder="stroke" stroke="white" strokeWidth={4}>
                    {f.etiqueta.length > 18 ? `${f.etiqueta.slice(0, 17)}…` : f.etiqueta}
                  </text>
                )}
              </g>
            );
          })}
        </svg>
        {nodos.map((n, i) => (
          <button
            key={i}
            type="button"
            data-nodo={i + 1}
            aria-pressed={seleccionado === i}
            onPointerDown={empezar(i)}
            onPointerMove={mover}
            onPointerUp={soltar(i)}
            onPointerCancel={() => (arrastre.current = null)}
            onKeyDown={teclado(i)}
            onClick={(e) => e.detail === 0 && onSeleccionar(i)}
            style={{ left: pos[i].x, top: pos[i].y, width: ANCHO_NODO, height: ALTO_NODO, touchAction: 'none' }}
            className={`absolute flex flex-col gap-1 p-2 rounded-xl border-2 bg-surface-container-lowest text-left shadow-sm cursor-grab active:cursor-grabbing select-none ${seleccionado === i ? 'border-primary ring-2 ring-primary/30' : avisos.has(i) ? 'border-aviso' : 'border-surface-container'}`}
          >
            <span className="flex items-center gap-1 font-label-sm text-label-sm text-secondary">
              <Icono nombre={ICONOS[n.tipo]} tamano="sm" />
              {i === 0 ? t.inicio : t.paso(i + 1)} · {t.tipos[n.tipo]}
              {avisos.has(i) && (
                <>
                  <Icono nombre="warning" tamano="sm" />
                  <span className="sr-only">{t.conAviso}</span>
                </>
              )}
            </span>
            <span className="font-body-sm text-body-sm text-on-surface line-clamp-2">{n.texto || t.sinTexto}</span>
          </button>
        ))}
      </div>
    </div>
  );
}
