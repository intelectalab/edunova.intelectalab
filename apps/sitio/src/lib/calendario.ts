// EDUN-267 · Calendario de la demo: solo días hábiles desde mañana, en hora de Lima.
// Las fechas viajan como texto «AAAA-MM-DD» para no depender de la zona horaria del navegador.

import { demo } from '@/contenido/provisional';

const ZONA = 'America/Lima';

/** Hoy en Lima como «AAAA-MM-DD». */
export function hoyLima(ahora: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: ZONA, year: 'numeric', month: '2-digit', day: '2-digit' }).format(ahora);
}

function aFecha(texto: string): Date {
  const [a, m, d] = texto.split('-').map(Number);
  return new Date(Date.UTC(a, m - 1, d));
}

function aTexto(f: Date): string {
  return f.toISOString().slice(0, 10);
}

/** 0 = domingo … 6 = sábado. */
export function diaSemana(fecha: string): number {
  return aFecha(fecha).getUTCDay();
}

export function esDiaHabilDesdeManana(fecha: string, hoy: string): boolean {
  const d = diaSemana(fecha);
  return fecha > hoy && d !== 0 && d !== 6;
}

export type DiaCalendario = { fecha: string; dia: number; disponible: boolean };

/** Celdas de un mes (lunes primero): huecos iniciales y los días con su disponibilidad. */
export function mesCalendario(anio: number, mes: number, hoy: string): { huecos: number; dias: DiaCalendario[] } {
  const primero = new Date(Date.UTC(anio, mes, 1));
  const total = new Date(Date.UTC(anio, mes + 1, 0)).getUTCDate();
  const dias: DiaCalendario[] = [];
  for (let d = 1; d <= total; d++) {
    const fecha = aTexto(new Date(Date.UTC(anio, mes, d)));
    dias.push({ fecha, dia: d, disponible: esDiaHabilDesdeManana(fecha, hoy) });
  }
  return { huecos: (primero.getUTCDay() + 6) % 7, dias };
}

/** «martes 10 de noviembre». */
export function textoFecha(fecha: string): string {
  const f = aFecha(fecha);
  return `${demo.diasLargos[f.getUTCDay()]} ${f.getUTCDate()} de ${demo.meses[f.getUTCMonth()]}`;
}

/** Mes y año visibles a partir de una fecha «AAAA-MM-DD». */
export function mesDe(fecha: string): { anio: number; mes: number } {
  const f = aFecha(fecha);
  return { anio: f.getUTCFullYear(), mes: f.getUTCMonth() };
}

/** Si hoy es el último día hábil del mes, el calendario abre en el mes siguiente. */
export function mesInicial(hoy: string): { anio: number; mes: number } {
  const { anio, mes } = mesDe(hoy);
  const hayDias = mesCalendario(anio, mes, hoy).dias.some((d) => d.disponible);
  return hayDias ? { anio, mes } : { anio: mes === 11 ? anio + 1 : anio, mes: (mes + 1) % 12 };
}
