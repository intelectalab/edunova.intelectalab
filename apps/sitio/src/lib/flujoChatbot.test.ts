import { describe, expect, it } from 'vitest';
import { acotar, aristas, disponer, huecoLibre, MARGEN, posiciones, revisar, SEPARACION_Y, trazo } from './flujoChatbot';
import type { NodoChatbot } from './landing';

const n = (tipo: NodoChatbot['tipo'], extra: Partial<NodoChatbot & { posicion: { x: number; y: number } | null }> = {}) => ({ tipo, texto: tipo, url: null, siguiente: null, opciones: [], ...extra });

describe('constructor visual del chatbot (EDUN-381)', () => {
  const flujo = [
    n('mensaje', { siguiente: 1 }),
    n('opciones', { opciones: [{ texto: 'Precios', destino: 2 }, { texto: 'Asesor', destino: 3 }] }),
    n('url', { url: '/planes' }),
    n('whatsapp'),
    n('fin'),
  ];

  it('dibuja una flecha por siguiente y por opción, con su etiqueta', () => {
    expect(aristas(flujo)).toEqual([
      { desde: 0, hasta: 1, etiqueta: null },
      { desde: 1, hasta: 2, etiqueta: 'Precios' },
      { desde: 1, hasta: 3, etiqueta: 'Asesor' },
    ]);
  });

  it('ordena por niveles desde el paso 1 y deja los sueltos en una fila aparte', () => {
    const p = disponer(flujo);
    expect(p[0]).toEqual({ x: MARGEN, y: MARGEN });
    expect(p[2].y).toBe(p[3].y);
    expect(p[3].x).toBeGreaterThan(p[2].x);
    expect(p[4].y).toBe(MARGEN + 3 * SEPARACION_Y);
  });

  it('respeta la posición guardada y completa las que faltan', () => {
    const p = posiciones([n('mensaje', { posicion: { x: 500, y: 600 } }), n('fin')]);
    expect(p[0]).toEqual({ x: 500, y: 600 });
    expect(p[1]).toEqual({ x: MARGEN, y: MARGEN + SEPARACION_Y });
  });

  it('avisa de pasos inalcanzables, sin salida y de un flujo que nunca cierra', () => {
    expect(revisar(flujo)).toEqual({ inalcanzables: [4], sinSalida: [], sinCierre: false });
    expect(revisar([n('mensaje'), n('opciones')])).toEqual({ inalcanzables: [1], sinSalida: [0, 1], sinCierre: true });
    expect(revisar([])).toEqual({ inalcanzables: [], sinSalida: [], sinCierre: false });
  });

  it('mantiene los pasos dentro del lienzo y busca un hueco para el nuevo', () => {
    expect(acotar({ x: -10, y: 99999 })).toEqual({ x: 0, y: 3000 - 84 });
    expect(huecoLibre([{ x: 24, y: 24 }, { x: 300, y: 164 }])).toEqual({ x: MARGEN, y: 164 + SEPARACION_Y });
  });

  it('traza la flecha hacia abajo o rodeando si vuelve hacia arriba', () => {
    expect(trazo({ x: 0, y: 0 }, { x: 0, y: 200 })).toMatch(/^M 104 84 C/);
    expect(trazo({ x: 0, y: 300 }, { x: 0, y: 0 })).toContain(' S ');
  });
});
