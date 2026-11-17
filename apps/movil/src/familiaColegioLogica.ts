// Lógica sin interfaz de autorizaciones y recojo en la app (EDUN-122, 187, 189), probada con Vitest.

/** DNI (8 dígitos) o carné de extranjería (9 a 12 letras o números). */
export function documentoValido(doc: string): boolean {
  return /^(\d{8}|[A-Z0-9]{9,12})$/.test(doc.replace(/\s+/g, '').toUpperCase());
}

/** Hora HH:MM de 24 horas. */
export function horaValida(hora: string): boolean {
  return /^([01]\d|2[0-3]):[0-5]\d$/.test(hora);
}

/** Primero lo que falta firmar; lo ya respondido o vencido va aparte. */
export function separarPendientes<T extends { estado: string }>(lista: T[]): { pendientes: T[]; resueltas: T[] } {
  return { pendientes: lista.filter((a) => a.estado === 'pendiente'), resueltas: lista.filter((a) => a.estado !== 'pendiente') };
}
