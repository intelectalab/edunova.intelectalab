// EDUN-196 · Cada aviso push trae `pantalla` (y a veces `id`): la app abre la pantalla relacionada.
// Las pantallas que aún no existen en la app caen en el inicio con el aviso visible.

export type Destino = { pantalla: 'inicio' | 'notificaciones' | 'preferencias'; id?: number; tipo?: string; aviso?: string };

const CONOCIDAS = new Set(['inicio', 'notificaciones', 'preferencias']);

export function destinoDeAviso(datos: Record<string, unknown> | null | undefined, titulo?: string | null): Destino {
  const pantalla = typeof datos?.pantalla === 'string' ? datos.pantalla : 'inicio';
  const id = typeof datos?.id === 'number' ? datos.id : typeof datos?.id === 'string' && /^\d+$/.test(datos.id) ? Number(datos.id) : undefined;
  const tipo = typeof datos?.tipo === 'string' ? datos.tipo : undefined;
  if (CONOCIDAS.has(pantalla)) {
    return { pantalla: pantalla as Destino['pantalla'], id, tipo };
  }
  return { pantalla: 'inicio', id, tipo, aviso: titulo ?? undefined };
}
