import { describe, expect, it } from 'vitest';
import { encolar, fusionar, leerCola, sincronizar, type Almacen, type Lote } from './cola';

function memoria(): Almacen & { datos: Map<string, string> } {
  const datos = new Map<string, string>();
  return { datos, getItem: async (k) => datos.get(k) ?? null, setItem: async (k, v) => void datos.set(k, v) };
}

const lote = (registros: Lote['registros'], fecha = '2027-04-05'): Lote => ({ seccion_id: 7, fecha, registros });

describe('cola de asistencia sin conexión (EDUN-94)', () => {
  it('fusiona lotes del mismo día quedándose con el registro más reciente por alumno', () => {
    const cola = fusionar([lote([{ alumno_id: 1, estado: 'F', registrado_at: '2027-04-05T08:10:00Z' }])], lote([
      { alumno_id: 1, estado: 'T', registrado_at: '2027-04-05T08:05:00Z' },
      { alumno_id: 2, estado: 'P', registrado_at: '2027-04-05T08:06:00Z' },
    ]));
    expect(cola).toHaveLength(1);
    expect(cola[0].registros.find((r) => r.alumno_id === 1)?.estado).toBe('F');
    expect(cola[0].registros).toHaveLength(2);
  });

  it('guarda en el almacén y sincroniza: lo enviado sale, lo que falla por red queda', async () => {
    const a = memoria();
    await encolar(a, lote([{ alumno_id: 1, estado: 'P', registrado_at: '2027-04-05T08:00:00Z' }]));
    await encolar(a, lote([{ alumno_id: 3, estado: 'F', registrado_at: '2027-04-06T08:00:00Z' }], '2027-04-06'));
    expect(await leerCola(a)).toHaveLength(2);

    const r = await sincronizar(a, async (l) => {
      if (l.fecha === '2027-04-06') throw Object.assign(new Error('sin red'), { estado: 0 });
    });
    expect(r).toEqual({ enviados: 1, pendientes: 1 });
    expect((await leerCola(a))[0].fecha).toBe('2027-04-06');

    // Un rechazo por datos inválidos no se reintenta.
    const r2 = await sincronizar(a, async () => {
      throw Object.assign(new Error('inválido'), { estado: 422 });
    });
    expect(r2.pendientes).toBe(0);
  });

  it('tolera un almacén corrupto', async () => {
    const a = memoria();
    a.datos.set('edunova.cola.asistencia', '{no es json');
    expect(await leerCola(a)).toEqual([]);
  });
});
