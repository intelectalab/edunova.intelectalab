import { describe, expect, it } from 'vitest';
import { distanciaLegible } from './serviciosLogica';

describe('movilidad para la familia (EDUN-166)', () => {
  it('muestra la distancia en metros o kilómetros', () => {
    expect(distanciaLegible(350)).toBe('350 m');
    expect(distanciaLegible(2400)).toBe('2.4 km');
  });
});
