import { useState } from 'react';
import { useSesion } from '../sesion/contexto';
import { useCarga } from '../panel/utilidades';

export type AnioResumen = { id: number; anio: number; tipo_periodo: string; estado: string };

/**
 * Años escolares del colegio y el elegido en pantalla (por defecto el abierto, si no el más
 * reciente). Lo usan plan de estudios, secciones, carga docente, horario y alumnos.
 */
export function useAnioEscolar() {
  const { pedir } = useSesion();
  const { datos, error } = useCarga(() => pedir<{ data: AnioResumen[] }>('/anios-escolares'));
  const [eleccion, setAnio] = useState<number | null>(null);
  const anios = datos?.data ?? [];
  const porDefecto = anios.find((a) => a.estado === 'abierto') ?? anios[0];
  const anio = eleccion ?? porDefecto?.id ?? null;
  return { anios, anio, setAnio, error, cargado: datos !== null };
}

export const TURNOS: Record<string, string> = { manana: 'Mañana', tarde: 'Tarde', noche: 'Noche' };
