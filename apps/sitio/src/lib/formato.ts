// Formatos es-PE del sitio (TRASPASO §13): S/ 1,234 · fechas dd/mm/aaaa · hora de Lima.

const SOLES = new Intl.NumberFormat('es-PE', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

/** «S/ 1,234» (sin decimales si son cero). */
export function soles(monto: number | string): string {
  return `S/ ${SOLES.format(typeof monto === 'string' ? Number(monto) : monto)}`;
}

/** Precio anual: 12 meses menos el descuento, redondeado a soles. */
export function precioAnual(mensual: number | string, descuentoPct: number): number {
  return Math.round(Number(mensual) * 12 * (1 - descuentoPct / 100));
}

/** «4:30» desde segundos. */
export function duracion(segundos: number): string {
  return `${Math.floor(segundos / 60)}:${String(segundos % 60).padStart(2, '0')}`;
}

/** «[A CONFIRMAR]» cuando un dato de la empresa está vacío (TRASPASO §7). */
export function oConfirmar(valor: unknown): string {
  return typeof valor === 'string' && valor.trim() !== '' ? valor : '[A CONFIRMAR]';
}
