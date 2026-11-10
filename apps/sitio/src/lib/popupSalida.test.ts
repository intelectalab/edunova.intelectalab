import { beforeEach, describe, expect, it } from 'vitest';
import { CLAVE_VISTO, esIntentoDeSalida, marcarConvertido, marcarVisto, puedeMostrarse } from './popupSalida';

// EDUN-271 · Reglas del popup de salida.
describe('popup de salida', () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
  });

  it('por sesión: se muestra una vez por visita', () => {
    expect(puedeMostrarse('sesion')).toBe(true);
    marcarVisto('sesion');
    expect(puedeMostrarse('sesion')).toBe(false);
    sessionStorage.clear();
    expect(puedeMostrarse('sesion')).toBe(true);
  });

  it('por día: vuelve a mostrarse a las 24 horas', () => {
    const ahora = Date.UTC(2026, 10, 10, 15);
    marcarVisto('dia', ahora);
    expect(localStorage.getItem(CLAVE_VISTO)).toBe(String(ahora));
    expect(puedeMostrarse('dia', ahora + 23 * 3600_000)).toBe(false);
    expect(puedeMostrarse('dia', ahora + 24 * 3600_000)).toBe(true);
  });

  it('nunca aparece a quien ya pidió una demo o dejó sus datos', () => {
    marcarConvertido();
    expect(puedeMostrarse('sesion')).toBe(false);
    expect(puedeMostrarse('siempre')).toBe(false);
  });

  it('el intento de salida es el puntero saliendo por el borde superior', () => {
    expect(esIntentoDeSalida({ clientY: 0, relatedTarget: null })).toBe(true);
    expect(esIntentoDeSalida({ clientY: -5, relatedTarget: null })).toBe(true);
    expect(esIntentoDeSalida({ clientY: 300, relatedTarget: null })).toBe(false);
    expect(esIntentoDeSalida({ clientY: 0, relatedTarget: document.body })).toBe(false);
  });
});
