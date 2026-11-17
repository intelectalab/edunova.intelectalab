// Ruta pública de verificación de documentos (EDUN-118).

/** Código en la URL pública: #/verificar o #/verificar/{codigo}. Devuelve null si no es esa ruta. */
export function codigoVerificacion(): string | null {
  const m = window.location.hash.match(/^#\/verificar(?:\/([A-Za-z0-9-]{0,24}))?$/);
  return m ? (m[1] ?? '').toUpperCase() : null;
}
