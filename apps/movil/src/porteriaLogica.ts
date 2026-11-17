// EDUN-214 · Lógica de la portería sin conexión (pura: se prueba con Vitest).

export type PuedeRecoger = { id: number | null; nombres: string; numero_documento: string; parentesco: string; apoderado: boolean };
export type AlumnoPorteria = {
  id: number; nombre: string; seccion: string; codigo_carne: string | null; numero_documento: string | null; pueden_recoger: PuedeRecoger[];
  /** EDUN-189 · Salida anticipada autorizada para hoy (hora, motivo y quién recoge). */
  salida_anticipada?: { hora: string; motivo: string; recoge: string | null } | null;
};
export type ListaTurno = { generado_at: string; alumnos: AlumnoPorteria[] };

const normal = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

/** Busca en la lista del turno por carné, DNI o nombre (sin señal). */
export function buscarEnLista(lista: AlumnoPorteria[], texto: string): AlumnoPorteria[] {
  const t = normal(texto);
  if (t.length < 3) return [];
  const exacto = lista.filter((a) => normal(a.codigo_carne ?? '') === t || normal(a.numero_documento ?? '') === t);
  if (exacto.length) return exacto;
  const partes = t.split(/\s+/);
  return lista.filter((a) => partes.every((p) => normal(a.nombre).includes(p))).slice(0, 15);
}

/** Quién recoge, por su documento (null si no está autorizado). */
export function verificar(alumno: AlumnoPorteria, documento: string): PuedeRecoger | null {
  const d = documento.trim().toUpperCase();
  return alumno.pueden_recoger.find((p) => p.numero_documento.toUpperCase() === d) ?? null;
}
