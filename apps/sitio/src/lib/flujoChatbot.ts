// EDUN-381 / SIT-028 · Lógica del constructor visual del chatbot (pura, se prueba con Vitest):
// posiciones en el lienzo, flechas entre pasos, orden automático y revisión del flujo.
import type { NodoChatbot } from './landing';

type Nodo = Pick<NodoChatbot, 'tipo' | 'siguiente' | 'opciones'> & { posicion?: { x: number; y: number } | null };

export const ANCHO_NODO = 208;
export const ALTO_NODO = 84;
export const SEPARACION_X = 248;
export const SEPARACION_Y = 140;
export const MARGEN = 24;
export const MAX_LIENZO = 3000;

/** Pasos que terminan la conversación por sí mismos. */
const TERMINALES = new Set(['captura', 'url', 'whatsapp', 'fin']);

export type Arista = { desde: number; hasta: number; etiqueta: string | null };

export function aristas(nodos: (Nodo & { opciones: { texto: string; destino: number }[] })[]): Arista[] {
  const salida: Arista[] = [];
  nodos.forEach((n, i) => {
    if (n.tipo === 'mensaje' && n.siguiente !== null && nodos[n.siguiente]) salida.push({ desde: i, hasta: n.siguiente, etiqueta: null });
    if (n.tipo === 'opciones') n.opciones.forEach((o) => nodos[o.destino] && salida.push({ desde: i, hasta: o.destino, etiqueta: o.texto || null }));
  });
  return salida;
}

/** Niveles desde el paso 1 (BFS); los que no se alcanzan quedan en una fila aparte al final. */
export function disponer(nodos: Nodo[]): { x: number; y: number }[] {
  const nivel = new Map<number, number>();
  if (nodos.length) nivel.set(0, 0);
  const cola = nodos.length ? [0] : [];
  const destinos = (n: Nodo) => (n.tipo === 'mensaje' ? (n.siguiente !== null ? [n.siguiente] : []) : n.tipo === 'opciones' ? n.opciones.map((o) => o.destino) : []);
  while (cola.length) {
    const i = cola.shift()!;
    for (const d of destinos(nodos[i])) {
      if (nodos[d] && !nivel.has(d)) {
        nivel.set(d, nivel.get(i)! + 1);
        cola.push(d);
      }
    }
  }
  const sueltos = nodos.map((_, i) => i).filter((i) => !nivel.has(i));
  const filaSueltos = nivel.size ? Math.max(...nivel.values()) + 1 : 0;
  sueltos.forEach((i) => nivel.set(i, filaSueltos));
  const porFila = new Map<number, number>();
  return nodos.map((_, i) => {
    const f = nivel.get(i)!;
    const columna = porFila.get(f) ?? 0;
    porFila.set(f, columna + 1);
    return { x: MARGEN + columna * SEPARACION_X, y: MARGEN + f * SEPARACION_Y };
  });
}

/** Posición guardada o, si falta, la del orden automático. */
export function posiciones(nodos: Nodo[]): { x: number; y: number }[] {
  const auto = disponer(nodos);
  return nodos.map((n, i) => (n.posicion ? n.posicion : auto[i]));
}

export const acotar = (p: { x: number; y: number }) => ({
  x: Math.round(Math.min(MAX_LIENZO - ANCHO_NODO, Math.max(0, p.x))),
  y: Math.round(Math.min(MAX_LIENZO - ALTO_NODO, Math.max(0, p.y))),
});

/** Un hueco libre para un paso nuevo: debajo de todo, a la izquierda. */
export function huecoLibre(actuales: { x: number; y: number }[]): { x: number; y: number } {
  if (!actuales.length) return { x: MARGEN, y: MARGEN };
  return acotar({ x: MARGEN, y: Math.max(...actuales.map((p) => p.y)) + SEPARACION_Y });
}

export type Revision = { inalcanzables: number[]; sinSalida: number[]; sinCierre: boolean };

/**
 * Revisión del flujo (avisos, no bloquean la publicación):
 * - inalcanzables: pasos a los que no se llega desde el paso 1;
 * - sinSalida: mensajes sin siguiente y preguntas sin opciones (la conversación se queda ahí);
 * - sinCierre: ningún camino termina en captura, enlace, WhatsApp o fin.
 */
export function revisar(nodos: Nodo[]): Revision {
  if (!nodos.length) return { inalcanzables: [], sinSalida: [], sinCierre: false };
  const vistos = new Set<number>([0]);
  const cola = [0];
  while (cola.length) {
    const n = nodos[cola.shift()!];
    const ds = n.tipo === 'mensaje' ? (n.siguiente !== null ? [n.siguiente] : []) : n.tipo === 'opciones' ? n.opciones.map((o) => o.destino) : [];
    for (const d of ds) {
      if (nodos[d] && !vistos.has(d)) {
        vistos.add(d);
        cola.push(d);
      }
    }
  }
  return {
    inalcanzables: nodos.map((_, i) => i).filter((i) => !vistos.has(i)),
    sinSalida: nodos.map((n, i) => ((n.tipo === 'mensaje' && n.siguiente === null) || (n.tipo === 'opciones' && !n.opciones.length) ? i : -1)).filter((i) => i >= 0),
    sinCierre: ![...vistos].some((i) => TERMINALES.has(nodos[i].tipo)),
  };
}

/** Punto de salida (abajo al centro) y de llegada (arriba al centro) para dibujar una flecha. */
export function trazo(desde: { x: number; y: number }, hasta: { x: number; y: number }): string {
  const x1 = desde.x + ANCHO_NODO / 2;
  const y1 = desde.y + ALTO_NODO;
  const x2 = hasta.x + ANCHO_NODO / 2;
  const y2 = hasta.y;
  if (y2 > y1 + 8) {
    const medio = (y1 + y2) / 2;
    return `M ${x1} ${y1} C ${x1} ${medio}, ${x2} ${medio}, ${x2} ${y2 - 6}`;
  }
  // Vuelve hacia arriba (o al costado): rodea por la derecha.
  const lado = Math.max(desde.x, hasta.x) + ANCHO_NODO + 32;
  return `M ${x1} ${y1} C ${x1} ${y1 + 40}, ${lado} ${y1 + 40}, ${lado} ${(y1 + y2) / 2} S ${x2} ${y2 - 40}, ${x2} ${y2 - 6}`;
}
