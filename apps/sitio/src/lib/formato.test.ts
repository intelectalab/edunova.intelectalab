import { describe, expect, it } from 'vitest';
import { duracion, oConfirmar, precioAnual, soles } from './formato';

describe('formatos es-PE', () => {
  it('soles con separador de miles y sin decimales vacíos', () => {
    expect(soles('1234.00')).toBe('S/ 1,234');
    expect(soles(500)).toBe('S/ 500');
    expect(soles('60.50')).toBe('S/ 60.5');
  });

  it('precio anual: ×12 menos el descuento', () => {
    expect(precioAnual('500.00', 10)).toBe(5400);
    expect(precioAnual(300, 0)).toBe(3600);
  });

  it('duración y datos por confirmar', () => {
    expect(duracion(270)).toBe('4:30');
    expect(oConfirmar(null)).toBe('[A CONFIRMAR]');
    expect(oConfirmar('  ')).toBe('[A CONFIRMAR]');
    expect(oConfirmar('20600000001')).toBe('20600000001');
  });
});
