// Lógica sin interfaz del aula en la app (EDUN-126, 130, 135, 142), probada con Vitest.
import { leerFecha } from './fechas';

export type Accesibilidad = { escala: 1 | 1.25 | 1.5; contraste: boolean };
export const ACCESIBILIDAD_INICIAL: Accesibilidad = { escala: 1, contraste: false };

/** Tamaños de letra disponibles: normal, grande y muy grande (se guardan en el teléfono). */
export function siguienteEscala(e: Accesibilidad['escala']): Accesibilidad['escala'] {
  return e === 1 ? 1.25 : e === 1.25 ? 1.5 : 1;
}

/** Lee la preferencia guardada (si está dañada o no existe, vuelve a la inicial). */
export function leerAccesibilidad(texto: string | null): Accesibilidad {
  try {
    const v = JSON.parse(texto ?? '') as Partial<Accesibilidad>;
    return { escala: v.escala === 1.25 || v.escala === 1.5 ? v.escala : 1, contraste: v.contraste === true };
  } catch {
    return ACCESIBILIDAD_INICIAL;
  }
}

/** Colores del aula según el contraste elegido. */
export function coloresAula(a: Accesibilidad, color: string) {
  return a.contraste ? { fondo: '#000000', texto: '#ffffff', suave: '#e5e7eb', acento: '#ffd400' } : { fondo: '#ffffff', texto: '#111827', suave: '#6b7280', acento: color };
}

/** El cuestionario está completo cuando cada pregunta tiene respuesta (relacionar: todos los pares). */
export function faltanRespuestas(preguntas: { id: number; tipo: string; opciones: unknown }[], respuestas: Record<number, unknown>): number {
  return preguntas.filter((p) => {
    const r = respuestas[p.id];
    if (p.tipo === 'relacionar') {
      const izq = (p.opciones as { izquierda?: string[] } | null)?.izquierda ?? [];
      return !r || izq.some((_, i) => (r as Record<string, unknown>)[i] === undefined);
    }
    return r === undefined || r === null || (typeof r === 'string' && r.trim() === '');
  }).length;
}

/** Texto del plazo de una tarea («vence hoy 18:00», «venció»). */
export function plazo(venceIso: string, ahora: Date = new Date()): string {
  const v = leerFecha(venceIso);
  if (v.getTime() < ahora.getTime()) return 'venció';
  const mismoDia = v.toDateString() === ahora.toDateString();
  const hora = `${String(v.getHours()).padStart(2, '0')}:${String(v.getMinutes()).padStart(2, '0')}`;
  return mismoDia ? `vence hoy ${hora}` : `vence el ${String(v.getDate()).padStart(2, '0')}/${String(v.getMonth() + 1).padStart(2, '0')} ${hora}`;
}
