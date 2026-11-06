// EDUN-209 · Cabeceras de seguridad de la web de gestión. Se usan en el servidor de Vite
// (desarrollo y vista previa) y se escriben en dist/_headers para el hosting (Cloudflare Pages).

function origen(url: string | undefined, porDefecto: string): string {
  try {
    return new URL(url ?? porDefecto).origin;
  } catch {
    return new URL(porDefecto).origin;
  }
}

export function politicaContenido(apiUrl: string | undefined, desarrollo: boolean): string {
  const api = origen(apiUrl, 'http://localhost:8100/api/v1');
  const directivas: Record<string, string[]> = {
    'default-src': ["'self'"],
    // En desarrollo Vite inyecta el script de recarga en línea.
    'script-src': ["'self'", ...(desarrollo ? ["'unsafe-inline'"] : [])],
    'style-src': ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
    'font-src': ["'self'", 'https://fonts.gstatic.com'],
    'img-src': ["'self'", 'data:', 'blob:'],
    'connect-src': ["'self'", api, ...(desarrollo ? ['ws:'] : [])],
    'frame-ancestors': ["'none'"],
    'base-uri': ["'self'"],
    'form-action': ["'self'"],
    'object-src': ["'none'"],
  };
  return Object.entries(directivas)
    .map(([k, v]) => `${k} ${v.join(' ')}`)
    .join('; ');
}

export function cabecerasSeguridad(apiUrl: string | undefined, desarrollo: boolean): Record<string, string> {
  return {
    'Content-Security-Policy': politicaContenido(apiUrl, desarrollo),
    'X-Frame-Options': 'DENY',
    'X-Content-Type-Options': 'nosniff',
    'Referrer-Policy': 'strict-origin-when-cross-origin',
    'Permissions-Policy': 'camera=(), microphone=(), geolocation=(), payment=()',
    ...(desarrollo ? {} : { 'Strict-Transport-Security': 'max-age=31536000; includeSubDomains' }),
  };
}

/** Archivo _headers de Cloudflare Pages con las cabeceras para todas las rutas. */
export function archivoHeaders(apiUrl: string | undefined): string {
  const lineas = Object.entries(cabecerasSeguridad(apiUrl, false)).map(([k, v]) => `  ${k}: ${v}`);
  return ['/*', ...lineas, ''].join('\n');
}
