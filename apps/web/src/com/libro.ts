// Ruta pública del libro de reclamaciones del colegio (EDUN-66).

/** Slug del colegio en la URL pública: #/libro/{slug}. */
export function slugLibro(): string | null {
  const m = window.location.hash.match(/^#\/libro\/([a-z0-9-]{2,60})$/);
  return m ? m[1] : null;
}
