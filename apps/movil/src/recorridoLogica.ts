// EDUN-213 · Lógica de la marca por QR en el recorrido (sin señal), probada con Vitest.

type AlumnoRuta = { asignacion_id: number; nombres: string; apellido_paterno: string; codigo_carne?: string | null; documento_hash?: string | null };

/**
 * El alumno de la ruta con ese carné (o con ese DNI, comparando su hash SHA-256: el teléfono no
 * guarda los DNI); null si no es de esta ruta.
 */
export function buscarEnRuta<T extends AlumnoRuta>(alumnos: T[], codigo: string, hashDocumento?: string | null): T | null {
  const c = codigo.trim().toUpperCase();
  if (!c) return null;
  return alumnos.find((a) => (a.codigo_carne ?? '').toUpperCase() === c || (!!hashDocumento && a.documento_hash === hashDocumento)) ?? null;
}
