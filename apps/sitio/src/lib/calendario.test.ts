import { describe, expect, it } from 'vitest';
import { esDiaHabilDesdeManana, hoyLima, mesCalendario, mesInicial, textoFecha } from './calendario';

// EDUN-267 · Calendario de la demo: días hábiles desde mañana, hora de Lima.
describe('calendario de la demo', () => {
  it('usa la fecha de Lima, no la del navegador', () => {
    // 03:00 UTC del 8 de noviembre = 22:00 del 7 en Lima.
    expect(hoyLima(new Date('2026-11-08T03:00:00Z'))).toBe('2026-11-07');
  });

  it('solo días hábiles desde mañana', () => {
    const hoy = '2026-11-09'; // lunes
    expect(esDiaHabilDesdeManana('2026-11-09', hoy)).toBe(false); // hoy
    expect(esDiaHabilDesdeManana('2026-11-10', hoy)).toBe(true);
    expect(esDiaHabilDesdeManana('2026-11-14', hoy)).toBe(false); // sábado
    expect(esDiaHabilDesdeManana('2026-11-15', hoy)).toBe(false); // domingo
  });

  it('arma el mes con lunes primero', () => {
    const { huecos, dias } = mesCalendario(2026, 10, '2026-11-07');
    expect(huecos).toBe(6); // el 1 de noviembre de 2026 es domingo
    expect(dias).toHaveLength(30);
    expect(dias.filter((d) => d.disponible).map((d) => d.dia)).toEqual([9, 10, 11, 12, 13, 16, 17, 18, 19, 20, 23, 24, 25, 26, 27, 30]);
  });

  it('si ya no quedan días en el mes, abre el siguiente', () => {
    expect(mesInicial('2026-11-30')).toEqual({ anio: 2026, mes: 11 });
    expect(mesInicial('2026-12-31')).toEqual({ anio: 2027, mes: 0 });
    expect(mesInicial('2026-11-07')).toEqual({ anio: 2026, mes: 10 });
  });

  it('escribe la fecha como en la confirmación', () => {
    expect(textoFecha('2026-11-10')).toBe('martes 10 de noviembre');
    expect(textoFecha('2026-09-07')).toBe('lunes 7 de setiembre');
  });
});
