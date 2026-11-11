import { describe, expect, it } from 'vitest';
import { cuotasMensuales, finDeMes } from './tipos';

describe('cronograma de pensiones (EDUN-70)', () => {
  it('calcula el último día del mes, también en febrero bisiesto', () => {
    expect(finDeMes('2027-02')).toBe('2027-02-28');
    expect(finDeMes('2028-02')).toBe('2028-02-29');
    expect(finDeMes('2027-04')).toBe('2027-04-30');
  });

  it('genera diez pensiones de marzo a diciembre que nunca vencen antes de su mes', () => {
    const cuotas = cuotasMensuales(2027, 5, 350, 31);
    expect(cuotas).toHaveLength(10);
    expect(cuotas[0]).toEqual({ concepto_cobro_id: 5, mes: '2027-03', emision: '2027-03-01', vencimiento: '2027-03-31', monto: 350 });
    expect(cuotas.find((q) => q.mes === '2027-04')?.vencimiento).toBe('2027-04-30');
    expect(cuotas.every((q) => q.vencimiento >= `${q.mes}-01` && q.vencimiento.startsWith(q.mes))).toBe(true);
  });
});
