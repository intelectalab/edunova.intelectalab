import { describe, expect, it } from 'vitest';
import { coloresAula, faltanRespuestas, leerAccesibilidad, plazo, siguienteEscala } from './aulaLogica';

describe('aula en la app (EDUN-126, 130, 142)', () => {
  it('la letra crece en tres pasos y vuelve a normal', () => {
    expect(siguienteEscala(1)).toBe(1.25);
    expect(siguienteEscala(1.25)).toBe(1.5);
    expect(siguienteEscala(1.5)).toBe(1);
  });

  it('la preferencia guardada dañada vuelve a la inicial', () => {
    expect(leerAccesibilidad('{"escala":1.5,"contraste":true}')).toEqual({ escala: 1.5, contraste: true });
    expect(leerAccesibilidad('basura')).toEqual({ escala: 1, contraste: false });
    expect(leerAccesibilidad('{"escala":3}')).toEqual({ escala: 1, contraste: false });
  });

  it('alto contraste: fondo negro y letra blanca', () => {
    expect(coloresAula({ escala: 1, contraste: true }, '#047857').fondo).toBe('#000000');
    expect(coloresAula({ escala: 1, contraste: false }, '#047857').acento).toBe('#047857');
  });

  it('cuenta las preguntas sin responder (relacionar exige todos los pares)', () => {
    const p = [
      { id: 1, tipo: 'opcion_multiple', opciones: ['a', 'b'] },
      { id: 2, tipo: 'relacionar', opciones: { izquierda: ['x', 'y'], derecha: ['1', '2'] } },
      { id: 3, tipo: 'respuesta_corta', opciones: null },
    ];
    expect(faltanRespuestas(p, {})).toBe(3);
    expect(faltanRespuestas(p, { 1: 0, 2: { 0: 1 }, 3: ' ' })).toBe(2);
    expect(faltanRespuestas(p, { 1: 0, 2: { 0: 1, 1: 0 }, 3: 'lima' })).toBe(0);
  });

  it('describe el plazo de la tarea', () => {
    const ahora = new Date(2027, 3, 5, 10, 0);
    expect(plazo(new Date(2027, 3, 5, 18, 0).toISOString(), ahora)).toBe('vence hoy 18:00');
    expect(plazo(new Date(2027, 3, 4, 18, 0).toISOString(), ahora)).toBe('venció');
    expect(plazo(new Date(2027, 3, 7, 8, 30).toISOString(), ahora)).toBe('vence el 07/04 08:30');
  });
});
