import { describe, expect, it } from 'vitest';
import { crearCifrado } from './cifrado';
import { agregar, bloques, describir, encolar, leerCola, MAX_POSICIONES, sincronizar, type Almacen, type Envio, type Operacion, type Posicion, type Salida } from './cola';
import { buscarEnLista, verificar, type AlumnoPorteria } from './porteriaLogica';

function memoria(): Almacen {
  const datos = new Map<string, string>();
  return { getItem: async (k) => datos.get(k) ?? null, setItem: async (k, v) => void datos.set(k, v) };
}
let n = 0;
const cifrado = crearCifrado(new Uint8Array(32).fill(9), (k) => Uint8Array.from({ length: k }, () => (n++ * 53) % 256));
const pos = (recorrido: number, minuto: number): Posicion => ({ recorrido_id: recorrido, latitud: -12.05, longitud: -77.04, registrado_at: `2027-04-05T07:${String(minuto).padStart(2, '0')}:00Z` });
const sinRed = () => Object.assign(new Error('sin red'), { estado: 0 });

describe('movilidad sin conexión (EDUN-163 / EDUN-165)', () => {
  it('agrupa posiciones del mismo recorrido y marcas en lotes; el fin va solo y al final', () => {
    let c: Operacion[] = [];
    const t = (s: number) => new Date(Date.UTC(2027, 3, 5, 7, 0, s));
    c = agregar(c, 'mov_pos', pos(1, 1), t(1));
    c = agregar(c, 'mov_pos', pos(1, 2), t(2));
    c = agregar(c, 'mov_evento', { recorrido_id: 1, asignacion_id: 4, tipo: 'subio', ocurrido_at: '2027-04-05T07:02:30Z' }, t(3));
    c = agregar(c, 'mov_pos', pos(2, 3), t(4));
    c = agregar(c, 'mov_fin', { recorrido_id: 1, finalizado_at: '2027-04-05T07:10:00Z' }, t(5));
    expect(bloques(c).map((b) => `${b[0].tipo}×${b.length}`)).toEqual(['mov_pos×2', 'mov_evento×1', 'mov_pos×1', 'mov_fin×1']);
  });

  it('no repite una marca tocada dos veces y limita las posiciones guardadas', () => {
    const marca = { recorrido_id: 1, asignacion_id: 4, tipo: 'subio' as const, ocurrido_at: '2027-04-05T07:02:30Z' };
    expect(agregar(agregar([], 'mov_evento', marca), 'mov_evento', marca)).toHaveLength(1);
    let c: Operacion[] = [];
    for (let i = 0; i < MAX_POSICIONES + 5; i++) c = agregar(c, 'mov_pos', { ...pos(1, 0), registrado_at: new Date(Date.UTC(2027, 3, 5, 6, 0, i)).toISOString() });
    expect(c).toHaveLength(MAX_POSICIONES);
    // Se quitan las más viejas.
    expect((c[0].datos as Posicion).registrado_at).toBe(new Date(Date.UTC(2027, 3, 5, 6, 0, 5)).toISOString());
  });

  it('sin señal se queda todo guardado; al volver se envía en orden: posiciones, marcas y fin', async () => {
    const a = memoria();
    await encolar(a, cifrado, 'mov_pos', pos(1, 1));
    await encolar(a, cifrado, 'mov_evento', { recorrido_id: 1, asignacion_id: 4, tipo: 'bajo', ocurrido_at: '2027-04-05T07:05:00Z' });
    await encolar(a, cifrado, 'mov_fin', { recorrido_id: 1, finalizado_at: '2027-04-05T07:10:00Z' });
    const cortado: Envio = { lista: async () => { throw sinRed(); }, qr: async () => { throw sinRed(); }, movPosiciones: async () => { throw sinRed(); } };
    expect((await sincronizar(a, cifrado, cortado)).detenido).toBe(true);
    expect(await leerCola(a, cifrado)).toHaveLength(3);

    const orden: string[] = [];
    const ok: Envio = {
      lista: async () => undefined, qr: async () => [],
      movPosiciones: async (r, ps) => void orden.push(`pos${r}:${ps.length}`),
      movEventos: async (es) => { orden.push(`evento:${es[0].tipo}`); return es.map((e) => ({ id: e.id, ok: true })); },
      movFin: async (f) => void orden.push(`fin${f.recorrido_id}`),
    };
    expect((await sincronizar(a, cifrado, ok)).enviados).toBe(3);
    expect(orden).toEqual(['pos1:1', 'evento:bajo', 'fin1']);
    expect(await leerCola(a, cifrado)).toEqual([]);
  });

  it('una versión que no sabe enviar un tipo lo deja guardado sin perderlo', async () => {
    const a = memoria();
    await encolar(a, cifrado, 'mov_evento', { recorrido_id: 1, asignacion_id: 4, tipo: 'subio', ocurrido_at: '2027-04-05T07:05:00Z' });
    await sincronizar(a, cifrado, { lista: async () => undefined, qr: async () => [] });
    expect(await leerCola(a, cifrado)).toHaveLength(1);
  });
});

describe('portería sin conexión (EDUN-214)', () => {
  const lista: AlumnoPorteria[] = [
    { id: 1, nombre: 'Quispe Rojas, Ana', seccion: '3.° A', codigo_carne: 'ENABC123', numero_documento: '71234567', pueden_recoger: [
      { id: null, nombres: 'Rosa Rojas', numero_documento: '40111222', parentesco: 'Madre', apoderado: true },
      { id: 9, nombres: 'Luis Quispe', numero_documento: 'CE1234567', parentesco: 'Tío', apoderado: false },
    ] },
    { id: 2, nombre: 'Núñez Pérez, Bruno', seccion: '4.° B', codigo_carne: 'ENXYZ999', numero_documento: '72222222', pueden_recoger: [] },
  ];

  it('busca por carné, por DNI o por nombre sin tildes', () => {
    expect(buscarEnLista(lista, 'enabc123').map((a) => a.id)).toEqual([1]);
    expect(buscarEnLista(lista, '72222222').map((a) => a.id)).toEqual([2]);
    expect(buscarEnLista(lista, 'nunez bru').map((a) => a.id)).toEqual([2]);
    expect(buscarEnLista(lista, 'an')).toEqual([]);
  });

  it('solo deja salir con un documento autorizado', () => {
    expect(verificar(lista[0], ' 40111222 ')?.parentesco).toBe('Madre');
    expect(verificar(lista[0], 'ce1234567')?.nombres).toBe('Luis Quispe');
    expect(verificar(lista[0], '99999999')).toBeNull();
    expect(verificar(lista[1], '40111222')).toBeNull();
  });

  it('las salidas sin señal van en lote; una rechazada queda con su motivo y no frena a las otras', async () => {
    const a = memoria();
    const s1: Salida = { alumno_id: 1, numero_documento: '40111222', salio_at: '2027-04-05T13:00:00Z' };
    const s2: Salida = { alumno_id: 2, numero_documento: '40111222', salio_at: '2027-04-05T13:01:00Z' };
    await encolar(a, cifrado, 'salida', s1);
    await encolar(a, cifrado, 'salida', s2);
    const r = await sincronizar(a, cifrado, {
      lista: async () => undefined, qr: async () => [],
      salidas: async (ss) => ss.map((s) => (s.alumno_id === 1 ? { id: s.id, ok: true } : { id: s.id, ok: false, mensaje: 'Esa persona no está autorizada para recoger a este alumno.' })),
    });
    expect(r.enviados).toBe(1);
    const quedan = await leerCola(a, cifrado);
    expect(quedan).toHaveLength(1);
    expect(quedan[0].error).toContain('no está autorizada');
    expect(describir(quedan[0])).toBe('Salida 2027-04-05 13:01');
  });
});

describe('marca por QR en el recorrido y atención sin señal (EDUN-213 / EDUN-216)', () => {
  it('reconoce al alumno de la ruta por carné o DNI y rechaza a los demás', async () => {
    const { buscarEnRuta } = await import('./recorridoLogica');
    const alumnos = [{ asignacion_id: 1, nombres: 'Ana', apellido_paterno: 'Ruiz', codigo_carne: 'ENABC123', numero_documento: '71234567' }];
    expect(buscarEnRuta(alumnos, 'enabc123')?.asignacion_id).toBe(1);
    expect(buscarEnRuta(alumnos, '71234567')?.asignacion_id).toBe(1);
    expect(buscarEnRuta(alumnos, 'ENOTRO999')).toBeNull();
  });

  it('las atenciones sin señal entran a la cola y salen en bloque', async () => {
    const almacen = memoria();
    await encolar(almacen, cifrado, 'atencion', { codigo: '71234567', motivo: 'Dolor de cabeza', accion: 'Reposo', atendido_at: '2027-04-05T10:00:00Z' });
    await encolar(almacen, cifrado, 'atencion', { codigo: '71234567', motivo: 'Dolor de cabeza', accion: 'Reposo', atendido_at: '2027-04-05T10:00:00Z' });
    const c = await leerCola(almacen, cifrado);
    expect(c).toHaveLength(1);
    expect(describir(c[0])).toContain('Tópico');
    const enviadas: string[] = [];
    const envio: Envio = { lista: async () => undefined, qr: async () => [], atenciones: async (a) => { enviadas.push(...a.map((x) => x.codigo)); return a.map((x) => ({ id: x.id, ok: true })); } };
    await sincronizar(almacen, cifrado, envio);
    expect(enviadas).toEqual(['71234567']);
    expect(await leerCola(almacen, cifrado)).toHaveLength(0);
  });
});
