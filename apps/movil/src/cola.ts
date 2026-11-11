// EDUN-94 / EDUN-197 / EDUN-215 / EDUN-218 · Cola sin conexión. Lógica pura (se prueba con Vitest);
// el almacenamiento (AsyncStorage) y el cifrado se inyectan desde colaDispositivo.ts.
//
// - Todo lo pendiente se guarda cifrado; sin la clave del llavero del teléfono no se lee.
// - Se envía en el orden en que se registró, en bloques (las lecturas de QR van de a 50).
// - Si se cae la red a mitad, se detiene para no desordenar y sigue en la próxima reconexión.
// - Un fallo del servidor se reintenta hasta 5 veces; luego queda «con error» a la vista.
// - Un rechazo por datos (4xx) no se reintenta: queda con error y su mensaje.
// - Los conflictos se resuelven por hora: cada registro lleva la hora real en que se tomó y el
//   servidor se queda con el más reciente.
import type { Cifrado } from './cifrado';

export type Registro = { alumno_id: number; estado: 'P' | 'T' | 'F' | 'J'; registrado_at: string };
export type Lote = { seccion_id: number; fecha: string; registros: Registro[] };
export type Lectura = { codigo: string; leido_at: string };

type Base = { id: string; creado_at: string; intentos: number; error?: string };
export type Operacion = (Base & { tipo: 'asistencia'; datos: Lote }) | (Base & { tipo: 'qr'; datos: Lectura });

export interface Almacen {
  getItem(clave: string): Promise<string | null>;
  setItem(clave: string, valor: string): Promise<void>;
}

export type ResultadoQr = { id: string; ok: boolean; mensaje?: string };
export interface Envio {
  lista(lote: Lote): Promise<void>;
  qr(lecturas: (Lectura & { id: string })[]): Promise<ResultadoQr[]>;
}

export const MAX_INTENTOS = 5;
export const TAM_BLOQUE_QR = 50;
const CLAVE = 'edunova.cola.v2';
const CLAVE_ANTIGUA = 'edunova.cola.asistencia';

let secuencia = 0;
export const nuevoId = (ahora = Date.now()) => `${ahora.toString(36)}-${(secuencia++).toString(36)}-${Math.random().toString(36).slice(2, 8)}`;

export async function leerCola(almacen: Almacen, cifrado: Cifrado): Promise<Operacion[]> {
  let cola: Operacion[] = [];
  try {
    const crudo = await almacen.getItem(CLAVE);
    const datos = crudo ? JSON.parse(cifrado.descifrar(crudo)) : [];
    cola = Array.isArray(datos) ? datos : [];
  } catch {
    cola = [];
  }
  // Migración: la cola de la versión anterior estaba en claro; se cifra y se borra.
  try {
    const antigua = await almacen.getItem(CLAVE_ANTIGUA);
    if (antigua) {
      const lotes = JSON.parse(antigua);
      if (Array.isArray(lotes)) for (const l of lotes as Lote[]) cola = fusionarLista(cola, l);
      await guardar(almacen, cifrado, cola);
      await almacen.setItem(CLAVE_ANTIGUA, '');
    }
  } catch {
    await almacen.setItem(CLAVE_ANTIGUA, '').catch(() => undefined);
  }
  return cola;
}

// Toda escritura de la cola pasa por aquí, una a la vez: leer, cambiar y guardar sin que otra
// escritura (p. ej. una lectura de QR mientras se sincroniza) se pierda en el medio.
let candado: Promise<unknown> = Promise.resolve();
function enSerie<T>(fn: () => Promise<T>): Promise<T> {
  const r = candado.then(fn, fn);
  candado = r.catch(() => undefined);
  return r;
}

async function modificar(almacen: Almacen, cifrado: Cifrado, cambio: (cola: Operacion[]) => Operacion[]): Promise<Operacion[]> {
  return enSerie(async () => {
    const cola = cambio(await leerCola(almacen, cifrado));
    await guardar(almacen, cifrado, cola);
    return cola;
  });
}

async function guardar(almacen: Almacen, cifrado: Cifrado, cola: Operacion[]) {
  await almacen.setItem(CLAVE, cifrado.cifrar(JSON.stringify(cola)));
}

/** Por alumno queda el registro más reciente (misma regla que el servidor). */
export function fusionar(previo: Lote, lote: Lote): Lote {
  const porAlumno = new Map(previo.registros.map((r) => [r.alumno_id, r]));
  for (const r of lote.registros) {
    const actual = porAlumno.get(r.alumno_id);
    if (!actual || actual.registrado_at <= r.registrado_at) porAlumno.set(r.alumno_id, r);
  }
  return { ...previo, registros: [...porAlumno.values()] };
}

export function fusionarLista(cola: Operacion[], lote: Lote, ahora = new Date()): Operacion[] {
  const previa = cola.find((o) => o.tipo === 'asistencia' && !o.error && o.datos.seccion_id === lote.seccion_id && o.datos.fecha === lote.fecha);
  if (!previa || previa.tipo !== 'asistencia') return [...cola, { id: nuevoId(ahora.getTime()), tipo: 'asistencia', creado_at: ahora.toISOString(), intentos: 0, datos: lote }];
  return cola.map((o) => (o === previa ? { ...previa, datos: fusionar(previa.datos, lote) } : o));
}

export function agregarLectura(cola: Operacion[], lectura: Lectura, ahora = new Date()): Operacion[] {
  // El mismo carné leído dos veces el mismo día: basta la primera (es la hora de llegada).
  const dia = lectura.leido_at.slice(0, 10);
  if (cola.some((o) => o.tipo === 'qr' && o.datos.codigo === lectura.codigo && o.datos.leido_at.slice(0, 10) === dia)) return cola;
  return [...cola, { id: nuevoId(ahora.getTime()), tipo: 'qr', creado_at: ahora.toISOString(), intentos: 0, datos: lectura }];
}

export const pendientes = (cola: Operacion[]) => cola.filter((o) => !o.error).length;
export const conError = (cola: Operacion[]) => cola.filter((o) => o.error);

export function encolarLista(almacen: Almacen, cifrado: Cifrado, lote: Lote): Promise<Operacion[]> {
  return modificar(almacen, cifrado, (c) => fusionarLista(c, lote));
}

export function encolarLectura(almacen: Almacen, cifrado: Cifrado, lectura: Lectura): Promise<Operacion[]> {
  return modificar(almacen, cifrado, (c) => agregarLectura(c, lectura));
}

export function descartar(almacen: Almacen, cifrado: Cifrado, id: string): Promise<Operacion[]> {
  return modificar(almacen, cifrado, (c) => c.filter((o) => o.id !== id));
}

export function reintentar(almacen: Almacen, cifrado: Cifrado, id: string): Promise<Operacion[]> {
  return modificar(almacen, cifrado, (c) => c.map((o) => (o.id === id ? { ...o, intentos: 0, error: undefined } : o)));
}

/** Bloques en orden: las lecturas de QR seguidas van juntas (hasta 50); cada lista va sola. */
export function bloques(cola: Operacion[]): Operacion[][] {
  const salida: Operacion[][] = [];
  for (const o of [...cola].filter((x) => !x.error).sort((a, b) => a.creado_at.localeCompare(b.creado_at))) {
    const ultimo = salida[salida.length - 1];
    if (o.tipo === 'qr' && ultimo && ultimo[0].tipo === 'qr' && ultimo.length < TAM_BLOQUE_QR) ultimo.push(o);
    else salida.push([o]);
  }
  return salida;
}

const sinRed = (e: unknown) => {
  const estado = (e as { estado?: number }).estado;
  return estado === undefined || estado === 0;
};
const reintentable = (e: unknown) => {
  const estado = (e as { estado?: number }).estado ?? 0;
  return estado >= 500 || estado === 429 || estado === 408;
};
const mensajeDe = (e: unknown) => (e instanceof Error ? e.message : 'Error al enviar.');

let enCurso: Promise<Resumen> | null = null;
export type Resumen = { enviados: number; pendientes: number; errores: number; detenido: boolean };

/** Envía lo pendiente. Una sola sincronización a la vez: si ya hay una en curso, se espera esa. */
export function sincronizar(almacen: Almacen, cifrado: Cifrado, envio: Envio): Promise<Resumen> {
  enCurso ??= ejecutar(almacen, cifrado, envio).finally(() => {
    enCurso = null;
  });
  return enCurso;
}

async function ejecutar(almacen: Almacen, cifrado: Cifrado, envio: Envio): Promise<Resumen> {
  const cola = await leerCola(almacen, cifrado);
  let enviados = 0;
  let detenido = false;
  // Lo que pasó con cada operación se anota y al final se aplica sobre la cola recién leída:
  // mientras se enviaba pudieron llegar registros nuevos (o fusionarse en una lista enviada).
  const enviadas = new Map<string, string>();
  const marcas = new Map<string, (o: Operacion) => Operacion>();
  const quitar = (ids: Set<string>) => ids.forEach((id) => enviadas.set(id, JSON.stringify(cola.find((o) => o.id === id)?.datos)));
  const marcar = (ids: Set<string>, cambio: (o: Operacion) => Operacion) => ids.forEach((id) => marcas.set(id, cambio));

  for (const bloque of bloques(cola)) {
    const ids = new Set(bloque.map((o) => o.id));
    try {
      if (bloque[0].tipo === 'asistencia') {
        await envio.lista(bloque[0].datos);
        quitar(ids);
        enviados++;
      } else {
        const resultados = await envio.qr(bloque.map((o) => ({ id: o.id, ...(o.datos as Lectura) })));
        const porId = new Map(resultados.map((r) => [r.id, r]));
        const bien = new Set(bloque.filter((o) => porId.get(o.id)?.ok).map((o) => o.id));
        for (const o of bloque) {
          const r = porId.get(o.id);
          if (r && !r.ok) marcar(new Set([o.id]), (x) => ({ ...x, error: r.mensaje ?? 'Rechazado.' }));
        }
        quitar(bien);
        enviados += bien.size;
      }
    } catch (e) {
      if (sinRed(e)) {
        detenido = true;
        break; // Sin señal: se sigue en la próxima reconexión, sin gastar intentos.
      }
      if (reintentable(e)) {
        marcar(ids, (o) => (o.intentos + 1 >= MAX_INTENTOS ? { ...o, intentos: o.intentos + 1, error: `${mensajeDe(e)} (${MAX_INTENTOS} intentos)` } : { ...o, intentos: o.intentos + 1 }));
        detenido = true;
        break; // Se respeta el orden: no se envía lo de después hasta que pase esto.
      }
      marcar(ids, (o) => ({ ...o, error: mensajeDe(e) }));
    }
  }
  const final = await modificar(almacen, cifrado, (actual) =>
    actual
      // Se quita lo enviado, salvo una lista que recibió registros nuevos mientras tanto (se reenvía; el servidor se queda con el más reciente).
      .filter((o) => !(enviadas.has(o.id) && enviadas.get(o.id) === JSON.stringify(o.datos)))
      .map((o) => (marcas.has(o.id) ? marcas.get(o.id)!(o) : o)),
  );
  return { enviados, pendientes: pendientes(final), errores: conError(final).length, detenido };
}
