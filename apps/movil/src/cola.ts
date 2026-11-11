// EDUN-94 · Cola de asistencia sin conexión. Lógica pura (se prueba con Vitest); el
// almacenamiento real (AsyncStorage) se inyecta desde asistencia.tsx.

export type Registro = { alumno_id: number; estado: 'P' | 'T' | 'F' | 'J'; registrado_at: string };
export type Lote = { seccion_id: number; fecha: string; registros: Registro[] };

export interface Almacen {
  getItem(clave: string): Promise<string | null>;
  setItem(clave: string, valor: string): Promise<void>;
}

const CLAVE = 'edunova.cola.asistencia';

export async function leerCola(almacen: Almacen): Promise<Lote[]> {
  try {
    const crudo = await almacen.getItem(CLAVE);
    const datos = crudo ? JSON.parse(crudo) : [];
    return Array.isArray(datos) ? datos : [];
  } catch {
    return [];
  }
}

/** Agrega o fusiona un lote: por alumno queda el registro más reciente (misma regla que el servidor). */
export function fusionar(cola: Lote[], lote: Lote): Lote[] {
  const previo = cola.find((l) => l.seccion_id === lote.seccion_id && l.fecha === lote.fecha);
  if (!previo) return [...cola, lote];
  const porAlumno = new Map(previo.registros.map((r) => [r.alumno_id, r]));
  for (const r of lote.registros) {
    const actual = porAlumno.get(r.alumno_id);
    if (!actual || actual.registrado_at <= r.registrado_at) porAlumno.set(r.alumno_id, r);
  }
  return cola.map((l) => (l === previo ? { ...l, registros: [...porAlumno.values()] } : l));
}

export async function encolar(almacen: Almacen, lote: Lote): Promise<number> {
  const cola = fusionar(await leerCola(almacen), lote);
  await almacen.setItem(CLAVE, JSON.stringify(cola));
  return cola.length;
}

/**
 * Envía lo pendiente. Lo que se envió bien sale de la cola; si falla la red, se queda para el
 * próximo intento. Devuelve cuántos lotes quedaron pendientes.
 */
export async function sincronizar(almacen: Almacen, enviar: (lote: Lote) => Promise<void>): Promise<{ enviados: number; pendientes: number }> {
  const cola = await leerCola(almacen);
  const quedan: Lote[] = [];
  let enviados = 0;
  for (const lote of cola) {
    try {
      await enviar(lote);
      enviados++;
    } catch (e) {
      // Un 422 (datos inválidos) no se reintenta para siempre; la red caída sí.
      const estado = (e as { estado?: number }).estado;
      if (estado === undefined || estado === 0 || estado >= 500 || estado === 429) quedan.push(lote);
    }
  }
  await almacen.setItem(CLAVE, JSON.stringify(quedan));
  return { enviados, pendientes: quedan.length };
}
