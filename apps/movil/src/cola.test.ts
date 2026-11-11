import { describe, expect, it } from 'vitest';
import { crearCifrado } from './cifrado';
import { bloques, descartar, encolarLectura, encolarLista, fusionarLista, leerCola, MAX_INTENTOS, reintentar, sincronizar, type Almacen, type Envio, type Lote } from './cola';

function memoria(): Almacen & { datos: Map<string, string> } {
  const datos = new Map<string, string>();
  return { datos, getItem: async (k) => datos.get(k) ?? null, setItem: async (k, v) => void datos.set(k, v) };
}
let n = 0;
const azar = (k: number) => Uint8Array.from({ length: k }, () => (n++ * 37) % 256);
const cifrado = crearCifrado(new Uint8Array(32).fill(7), azar);
const lote = (registros: Lote['registros'], fecha = '2027-04-05'): Lote => ({ seccion_id: 7, fecha, registros });
const sinRed = () => Object.assign(new Error('sin red'), { estado: 0 });
const envioOk = (): Envio & { listas: Lote[]; qrs: string[][] } => {
  const e = { listas: [] as Lote[], qrs: [] as string[][], lista: async (l: Lote) => void e.listas.push(l), qr: async (ls: { id: string; codigo: string }[]) => {
    e.qrs.push(ls.map((l) => l.codigo));
    return ls.map((l) => ({ id: l.id, ok: true }));
  } };
  return e;
};

describe('cifrado de la cola (EDUN-197)', () => {
  it('cifra y descifra; con otra clave no se lee', () => {
    const texto = JSON.stringify({ alumno: 'Ana', estado: 'F' });
    const c = cifrado.cifrar(texto);
    expect(c).not.toContain('Ana');
    expect(cifrado.descifrar(c)).toBe(texto);
    expect(() => crearCifrado(new Uint8Array(32).fill(8), azar).descifrar(c)).toThrow();
  });

  it('el almacén no guarda nada en claro', async () => {
    const a = memoria();
    await encolarLista(a, cifrado, lote([{ alumno_id: 41, estado: 'F', registrado_at: '2027-04-05T08:00:00Z' }]));
    expect([...a.datos.values()].join('')).not.toContain('alumno_id');
    expect(await leerCola(a, cifrado)).toHaveLength(1);
  });

  it('migra la cola en claro de la versión anterior y la borra', async () => {
    const a = memoria();
    a.datos.set('edunova.cola.asistencia', JSON.stringify([lote([{ alumno_id: 1, estado: 'P', registrado_at: '2027-04-05T08:00:00Z' }])]));
    expect(await leerCola(a, cifrado)).toHaveLength(1);
    expect(a.datos.get('edunova.cola.asistencia')).toBe('');
  });

  it('tolera un almacén corrupto', async () => {
    const a = memoria();
    a.datos.set('edunova.cola.v2', '{no es json');
    expect(await leerCola(a, cifrado)).toEqual([]);
  });
});

describe('cola sin conexión (EDUN-94/215/218)', () => {
  it('fusiona listas del mismo día: por alumno gana el registro más reciente', () => {
    const c1 = fusionarLista([], lote([{ alumno_id: 1, estado: 'F', registrado_at: '2027-04-05T08:10:00Z' }]));
    const c2 = fusionarLista(c1, lote([{ alumno_id: 1, estado: 'T', registrado_at: '2027-04-05T08:05:00Z' }, { alumno_id: 2, estado: 'P', registrado_at: '2027-04-05T08:06:00Z' }]));
    expect(c2).toHaveLength(1);
    const datos = c2[0].datos as Lote;
    expect(datos.registros.find((r) => r.alumno_id === 1)?.estado).toBe('F');
    expect(datos.registros).toHaveLength(2);
  });

  it('agrupa lecturas de QR seguidas en bloques de 50 y respeta el orden', async () => {
    const a = memoria();
    for (let i = 0; i < 60; i++) await encolarLectura(a, cifrado, { codigo: `C${i}`, leido_at: '2027-04-05T07:30:00Z' });
    await encolarLectura(a, cifrado, { codigo: 'C0', leido_at: '2027-04-05T07:40:00Z' }); // repetido del día: no se duplica
    const bs = bloques(await leerCola(a, cifrado));
    expect(bs.map((b) => b.length)).toEqual([50, 10]);
  });

  it('envía en orden; si se cae la red se detiene sin gastar intentos', async () => {
    const a = memoria();
    await encolarLista(a, cifrado, lote([{ alumno_id: 1, estado: 'P', registrado_at: '2027-04-05T08:00:00Z' }]));
    await encolarLectura(a, cifrado, { codigo: 'Q1', leido_at: '2027-04-05T08:01:00Z' });
    await encolarLista(a, cifrado, lote([{ alumno_id: 3, estado: 'F', registrado_at: '2027-04-06T08:00:00Z' }], '2027-04-06'));
    const e = envioOk();
    let llamadas = 0;
    const r = await sincronizar(a, cifrado, { ...e, qr: async () => { llamadas++; throw sinRed(); } });
    expect(r).toMatchObject({ enviados: 1, pendientes: 2, errores: 0, detenido: true });
    expect(llamadas).toBe(1);
    expect((await leerCola(a, cifrado)).every((o) => o.intentos === 0)).toBe(true);

    const r2 = await sincronizar(a, cifrado, e);
    expect(r2).toMatchObject({ enviados: 2, pendientes: 0 });
    expect(e.qrs).toEqual([['Q1']]);
    expect(e.listas.map((l) => l.fecha)).toEqual(['2027-04-05', '2027-04-06']);
  });

  it('reintenta errores del servidor hasta 5 veces y luego queda con error; se puede reintentar o descartar', async () => {
    const a = memoria();
    await encolarLectura(a, cifrado, { codigo: 'Q9', leido_at: '2027-04-05T08:01:00Z' });
    const caido: Envio = { lista: async () => undefined, qr: async () => { throw Object.assign(new Error('Servidor ocupado'), { estado: 503 }); } };
    for (let i = 0; i < MAX_INTENTOS - 1; i++) expect((await sincronizar(a, cifrado, caido)).errores).toBe(0);
    const r = await sincronizar(a, cifrado, caido);
    expect(r).toMatchObject({ pendientes: 0, errores: 1 });
    const [op] = await leerCola(a, cifrado);
    expect(op.error).toContain('5 intentos');

    await reintentar(a, cifrado, op.id);
    expect((await sincronizar(a, cifrado, envioOk())).enviados).toBe(1);

    await encolarLectura(a, cifrado, { codigo: 'Q10', leido_at: '2027-04-05T08:02:00Z' });
    const id = (await leerCola(a, cifrado))[0].id;
    expect(await descartar(a, cifrado, id)).toEqual([]);
  });

  it('un rechazo por datos no se reintenta y no frena lo siguiente', async () => {
    const a = memoria();
    await encolarLectura(a, cifrado, { codigo: 'MALO', leido_at: '2027-04-05T08:01:00Z' });
    await encolarLectura(a, cifrado, { codigo: 'BUENO', leido_at: '2027-04-05T08:02:00Z' });
    const r = await sincronizar(a, cifrado, { lista: async () => undefined, qr: async (ls) => ls.map((l) => ({ id: l.id, ok: l.codigo === 'BUENO', mensaje: 'Carné no reconocido' })) });
    expect(r).toMatchObject({ enviados: 1, pendientes: 0, errores: 1 });
    expect((await leerCola(a, cifrado))[0].error).toBe('Carné no reconocido');
  });

  it('una sola sincronización a la vez', async () => {
    const a = memoria();
    await encolarLista(a, cifrado, lote([{ alumno_id: 1, estado: 'P', registrado_at: '2027-04-05T08:00:00Z' }]));
    const e = envioOk();
    const [r1, r2] = await Promise.all([sincronizar(a, cifrado, e), sincronizar(a, cifrado, e)]);
    expect(r1).toBe(r2);
    expect(e.listas).toHaveLength(1);
  });
});
