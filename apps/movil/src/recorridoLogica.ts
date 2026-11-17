// EDUN-213 · Lógica de la marca por QR en el recorrido (sin señal), probada con Vitest.

type AlumnoRuta = { asignacion_id: number; nombres: string; apellido_paterno: string; codigo_carne?: string | null; numero_documento?: string | null };

/** El alumno de la ruta con ese carné o DNI; null si no es de esta ruta. */
export function buscarEnRuta<T extends AlumnoRuta>(alumnos: T[], codigo: string): T | null {
  const c = codigo.trim().toUpperCase();
  if (!c) return null;
  return alumnos.find((a) => (a.codigo_carne ?? '').toUpperCase() === c || (a.numero_documento ?? '').toUpperCase() === c) ?? null;
}
