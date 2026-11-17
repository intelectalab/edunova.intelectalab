import { describe, expect, it } from 'vitest';
import { documentoValido, horaValida, separarPendientes } from './familiaColegioLogica';

describe('autorizaciones y recojo en la app (EDUN-122, 187, 189)', () => {
  it('acepta DNI y carné de extranjería, con o sin espacios', () => {
    expect(documentoValido('4567 8901')).toBe(true);
    expect(documentoValido('ce1234567')).toBe(true);
    expect(documentoValido('123')).toBe(false);
  });

  it('valida la hora de la salida', () => {
    expect(horaValida('11:30')).toBe(true);
    expect(horaValida('24:00')).toBe(false);
    expect(horaValida('9:30')).toBe(false);
  });

  it('separa lo que falta firmar de lo ya respondido', () => {
    const r = separarPendientes([{ estado: 'pendiente' }, { estado: 'acepta' }, { estado: 'vencida' }]);
    expect(r.pendientes).toHaveLength(1);
    expect(r.resueltas).toHaveLength(2);
  });
});
