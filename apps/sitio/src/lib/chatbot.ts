// EDUN-375 / SIT-008 · Reglas del chatbot (sin React, para probarlas con Vitest).

export type Horario = { dias: number[]; desde: string; hasta: string };

/** Día (1 = lunes … 7 = domingo) y hora «HH:MM» en Lima. */
export function ahoraLima(fecha = new Date()): { dia: number; hora: string } {
  const partes = new Intl.DateTimeFormat('en-GB', { timeZone: 'America/Lima', weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(fecha);
  const p = (t: string) => partes.find((x) => x.type === t)?.value ?? '';
  const dias: Record<string, number> = { Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6, Sun: 7 };
  return { dia: dias[p('weekday')] ?? 1, hora: `${p('hour') === '24' ? '00' : p('hour')}:${p('minute')}` };
}

/** ¿Hay atención ahora? Desde incluido, hasta excluido, en hora de Lima. */
export function enHorario(h: Horario, fecha = new Date()): boolean {
  const { dia, hora } = ahoraLima(fecha);
  return h.dias.includes(dia) && hora >= h.desde && hora < h.hasta;
}

/** Enlace de WhatsApp con mensaje inicial (número solo con dígitos y código de país). */
export function enlaceWhatsapp(numero: string, mensaje?: string | null): string {
  const n = numero.replace(/\D/g, '');
  return `https://wa.me/${n}${mensaje ? `?text=${encodeURIComponent(mensaje)}` : ''}`;
}
